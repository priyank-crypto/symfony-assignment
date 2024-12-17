<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class DocumentService
{
    private string $storageDirectory;
    private LoggerInterface $logger;

    public function __construct(KernelInterface $storageDirectory, LoggerInterface $logger)
    {

        $this->storageDirectory = $storageDirectory->getProjectDir() . '/var/storage/documents';
        $this->logger = $logger;
    }

    public function fetchAndStoreDocuments(string $apiUrl): array
    {
        $client = HttpClient::create();

        $response = $client->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('Failed to fetch documents from API', ['status_code' => $response->getStatusCode()]);
            throw new \RuntimeException('API request failed with status code ' . $response->getStatusCode());
        }

        $documents = $response->toArray();

        $storedFiles = [];

        foreach ($documents as $doc) {
            try {
                $this->validateDocument($doc);
                // get decoded certificate
                $decodedFile = base64_decode($doc['certificate']);

                $filename = $this->generateFilename($doc['description'], $doc['doc_no']);
                $filePath = $this->storageDirectory . '/' . $filename;

                // Check the storage directory exists if not create it 
                $this->checkDirectoryExists($this->storageDirectory);

                file_put_contents($filePath, $decodedFile);
                $storedFiles[] = $filePath;

                $this->logger->info('Document stored successfully', ['file' => $filePath]);
            } catch (\Exception $e) {
                $this->logger->error('Error processing document', [
                    'doc_no' => $doc['doc_no'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $storedFiles;
    }

    private function validateDocument(array $doc): void
    {
        //Checked for required fields, need to make output file 
        $requiredFields = ['description', 'doc_no', 'certificate'];

        foreach ($requiredFields as $field) {
            if (!isset($doc[$field]) || empty($doc[$field])) {
                throw new \InvalidArgumentException(sprintf('Missing or empty required field: %s', $field));
            }
        }
    }

    private function generateFilename(string $description, string $docNo): string
    {
        $safeDescription = preg_replace('/[^a-zA-Z0-9_-]/', '_', $description);
        return sprintf('%s_%s.docx', $safeDescription, $docNo);
    }

    private function checkDirectoryExists(string $directory): void
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists($directory)) {
            try {
                $filesystem->mkdir($directory);
            } catch (IOExceptionInterface $e) {
                $this->logger->error('Failed to create directory', [
                    'directory' => $directory,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Failed to create directory: ' . $e->getMessage());
            }
        }
    }
}
