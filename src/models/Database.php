<?php

namespace Notmyhostname\Posse\Models;

use Kirby\Database\Database as DB;
use Kirby\Exception\Exception;

class Database
{
    protected $db;
    protected $initialized = false;
    protected $dbPath;

    /**
     * Static flag to track if table initialization has been performed
     * This ensures we only check for tables once per PHP execution
     */
    private static $tablesInitialized = false;

    public function __construct()
    {
        $siteRoot = kirby()->root('site');
        if (empty($siteRoot)) {
            $siteRoot = __DIR__;
        }
        $this->dbPath = $siteRoot . '/db/posse.sqlite';

        // Create the database directory if it doesn't exist
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("Failed to create database directory: " . $dir);
            }
        }

        // Check if database file exists, create it if it doesn't
        $newDatabase = false;
        if (!file_exists($this->dbPath)) {
            $newDatabase = true;
            if (!touch($this->dbPath)) {
                throw new Exception("Failed to create database file: " . $this->dbPath);
            }
            // Set permissions to ensure the file is writable
            chmod($this->dbPath, 0666);
        }

        if (!is_writable($this->dbPath)) {
            throw new Exception("Cannot write to database file: " . $this->dbPath);
        }

        if (empty($this->dbPath)) {
            throw new Exception("Cannot determine valid database path.");
        }

        // Connect to the SQLite database
        try {
            $this->db = new DB([
                'type'     => 'sqlite',
                'database' => $this->dbPath,
            ]);

            // Only initialize tables if this is a new database or if tables haven't been initialized yet
            if ($newDatabase || !self::$tablesInitialized) {
                $this->initializeTables();
                self::$tablesInitialized = true;
            }

            // IMPORTANT: We need to set this to true regardless of whether we just initialized the tables
            // The initialized flag is used by other methods to determine if they need to call initialize()
            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('Database: Connection error: ' . $e->getMessage());
            throw new Exception("Database connection error: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize database tables and indexes
     */
    private function initializeTables()
    {
        // Create the syndication table with direct SQL execution
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS syndications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_uuid TEXT NOT NULL,
                page_title TEXT NOT NULL,
                page_url TEXT NOT NULL,
                published_at DATETIME NOT NULL,
                syndicate_after DATETIME NOT NULL,
                syndicated_at DATETIME,
                syndicated_url TEXT,
                ignored INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                service TEXT NOT NULL
            )
        ');

        // Create a UNIQUE index on page_uuid and service to ensure no duplicates
        $this->db->execute('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_uuid_service
            ON syndications (page_uuid, service)
        ');
    }
    
    /**
     * Initialize method - called by other methods as a safety check
     * This is now only a fallback since initialization happens in the constructor
     */
    protected function initialize()
    {
        // Database is now initialized directly in the constructor
        // This is just a safety check
        if ($this->initialized) {
            return;
        }

        // If we get here, something went wrong with the constructor initialization
        // Let's initialize the tables again just to be safe
        $this->initializeTables();
        $this->initialized = true;
    }
    
    /**
     * Add a page to the syndication queue
     */
    public function addToQueue($page, $service)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        try {
            // Get the UUID from the page (all Kirby pages have UUIDs)
            $pageUuid = $page->uuid()->id();

            // Get published date (prefer date field, fallback to published)
            $publishedAt = null;
            if ($page->date()->exists()) {
                $publishedAt = $page->date()->toDate('Y-m-d H:i:s');
            } elseif ($page->published()->exists()) {
                $publishedAt = $page->published()->toDate('Y-m-d H:i:s');
            } else {
                $publishedAt = gmdate('Y-m-d H:i:s');
            }

            // Get delay from config (default to 60 minutes if not set)
            $config = new \Notmyhostname\Posse\Models\Config();
            $delay = $config->option('syndication_delay', 60);
            
            // Current time in UTC for consistency
            $currentTime = gmdate('Y-m-d H:i:s');
            
            // Calculate syndicate_after as current time + configured delay (in UTC)
            $syndicateAfter = gmdate('Y-m-d H:i:s', strtotime($currentTime . ' +' . $delay . ' minutes'));

            // Check if this page + service combination already exists
            $existing = $this->db->table('syndications')
                ->where('page_uuid', '=', $pageUuid)
                ->where('service', '=', $service)
                ->first();
                
            if ($existing) {
                // Update the existing record
                $values = [
                    'page_title' => (string)$page->title()->value(),
                    'page_url' => (string)$page->url(),
                    'published_at' => (string)$publishedAt,
                    'syndicate_after' => (string)$syndicateAfter,
                    'ignored' => 0, // Reset ignore flag
                    'updated_at' => (string)gmdate('Y-m-d H:i:s')
                ];

                return $this->db->table('syndications')
                    ->where('id', '=', (int)$existing->id)
                    ->update($values);
            }

            // Insert new record
            $values = [
                'page_uuid' => (string)$pageUuid,
                'page_title' => (string)$page->title()->value(),
                'page_url' => (string)$page->url(),
                'published_at' => (string)$publishedAt,
                'syndicate_after' => (string)$syndicateAfter,
                'service' => (string)$service,
                'syndicated_at' => null,
                'ignored' => 0,
                'created_at' => (string)gmdate('Y-m-d H:i:s'),
                'updated_at' => (string)gmdate('Y-m-d H:i:s')
            ];
            
            try {
                return $this->db->table('syndications')->insert($values);
            } catch (\Exception $e) {
                // If constraint violation (unlikely with UUIDs, but possible with race conditions)
                if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $existingRecord = $this->db->table('syndications')
                        ->where('page_uuid', '=', $pageUuid)
                        ->where('service', '=', $service)
                        ->first();
                        
                    if ($existingRecord) {
                        // Update existing record
                        $updateValues = [
                            'page_title' => (string)$page->title()->value(),
                            'page_url' => (string)$page->url(),
                            'published_at' => (string)$publishedAt,
                            'syndicate_after' => (string)$syndicateAfter,
                            'ignored' => 0,
                            'updated_at' => (string)gmdate('Y-m-d H:i:s')
                        ];
                        
                        return $this->db->table('syndications')
                            ->where('id', '=', (int)$existingRecord->id)
                            ->update($updateValues);
                    }
                }
                
                error_log('Database: Insert error: ' . $e->getMessage());
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin: ' . $e->getMessage());
            throw new Exception("Failed to add to queue: " . $e->getMessage());
        }
    }
    
    /**
     * Get all items in the syndication queue
     * 
     * @param array $options Optional filter options
     * @return array The queue items
     */
    public function getQueue($options = [])
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        try {
            // Build query with Kirby's query builder
            $query = $this->db->table('syndications');
            
            // Filter by service if provided
            if (isset($options['service'])) {
                $query->where('service', '=', $options['service']);
            }
            
            // Filter by syndicated status - always use SQL syntax for NULL values
            if (isset($options['syndicated']) && $options['syndicated']) {
                // For history items, show EITHER:
                // 1. Items that have syndicated_at value (regardless of ignored status) OR
                // 2. Items with ignored=1 (regardless of syndicated_at status)
                $query->where('((syndicated_at IS NOT NULL AND syndicated_at != "null" AND syndicated_at != "") OR ignored = 1)');
            } else {
                // Default: only return unsyndicated and unignored items 
                $query->where('((syndicated_at IS NULL OR syndicated_at = "null" OR syndicated_at = "") AND ignored = 0)');
            }
            
            // Order by (default: syndicate_after ASC)
            $orderBy = $options['orderBy'] ?? 'syndicate_after';
            $direction = $options['direction'] ?? 'ASC';
            $query->order($orderBy . ' ' . $direction);
            
            // Execute query
            $result = $query->all()->toArray();
            
            // Ensure we return an empty array, not null
            if (!is_array($result)) {
                $result = [];
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log('Database: Error getting queue: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark an item as syndicated
     */
    public function markSyndicated($id, $syndicatedUrl)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        try {
            // Always use UTC timestamps for consistency
            $timestamp = gmdate('Y-m-d H:i:s');

            // Mark syndicated operation

            // Find the record first to avoid type issues
            $record = $this->db->table('syndications')->where('id', '=', $id)->first();

            if (!$record) {
                // No record found with this ID
                return false;
            }
            
            // Record found
            
            // Ensure the syndicated URL is also not null or empty
            if (is_null($syndicatedUrl) || empty($syndicatedUrl)) {
                $syndicatedUrl = 'https://example.com/placeholder'; // Placeholder URL
            }
            
            // Create values array with guaranteed non-empty values
            $values = [
                'syndicated_at' => (string)$timestamp, // Explicitly set as string timestamp
                'syndicated_url' => (string)$syndicatedUrl, // Explicitly cast to string
                'updated_at' => (string)$timestamp
            ];
            
            // Make sure all values are the correct type
            foreach ($values as $key => $value) {
                if (!is_string($value) && !is_int($value) && !is_null($value)) {
                    $values[$key] = (string)$value;
                }
            }

            // Set syndicated values
            
            // Explicitly verify each value is not empty
            if (empty($values['syndicated_at'])) {
                $values['syndicated_at'] = $timestamp;
            }
            
            if (empty($values['syndicated_url'])) {
                $values['syndicated_url'] = 'https://example.com/placeholder';
            }
            
            if (empty($values['updated_at'])) {
                $values['updated_at'] = $timestamp;
            }
            
            // Process with final values

            // Use Kirby's query builder correctly with an array of values
            return $this->db->table('syndications')
                ->where('id', '=', $record->id)
                ->update($values);
        } catch (\Exception $e) {
            error_log('Database: Error marking as syndicated: ' . $e->getMessage());
            throw new Exception("Failed to mark as syndicated: " . $e->getMessage());
        }
    }
    
    /**
     * Mark an item as ignored
     */
    public function markIgnored($id, $ignored = true)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        try {
            // Use direct SQL execution for consistency
            $timestamp = gmdate('Y-m-d H:i:s'); // Use UTC

            // Mark ignored operation

            // Find the record first to avoid type issues
            $record = $this->db->table('syndications')->where('id', '=', $id)->first();

            if (!$record) {
                // No record found with this ID
                return false;
            }
            
            // Record found

            $ignoredValue = $ignored ? 1 : 0;

            // Use Kirby's query builder correctly with an array of values
            $values = [
                'ignored' => $ignoredValue,
                'updated_at' => (string)$timestamp,
            ];
            
            // Make sure all values are the correct type
            foreach ($values as $key => $value) {
                if (!is_string($value) && !is_int($value) && !is_null($value)) {
                    $values[$key] = (string)$value;
                }
            }
            
            // We deliberately DON'T set syndicated_at for ignored items anymore
            // so they can be distinguished from properly syndicated items

            return $this->db->table('syndications')
                ->where('id', '=', (int)$record->id)
                ->update($values);
        } catch (\Exception $e) {
            error_log('Database: Error marking as ignored: ' . $e->getMessage());
            throw new Exception("Failed to mark as ignored: " . $e->getMessage());
        }
    }
    
    /**
     * Get a single syndication by ID
     */
    public function getSyndication($id)
    {
        if (!$this->initialized) {
            $this->initialize();
        }
        
        try {
            // Ensure ID is an integer for consistency
            $id = (int)$id;
            return $this->db->table('syndications')->where('id', '=', $id)->first();
        } catch (\Exception $e) {
            throw new Exception("Failed to get syndication: " . $e->getMessage());
        }
    }
    
    /**
     * Get the database connection
     * 
     * @return \Kirby\Database\Database The database connection
     */
    public function getDatabase()
    {
        if (!$this->initialized) {
            $this->initialize();
        }
        
        return $this->db;
    }
    
    /**
     * Unignore an item and set it back to the queue
     * This is a helper method for the API endpoint
     */
public function unignoreItem($id, int $delay = 60): bool
    {
        // Unignore item operation
        if (!$this->initialized) {
            $this->initialize();
        }
        
        try {
            // Make sure ID is an integer
            $id = intval($id);
            
            // Processing unignore
            
            // Current time in UTC
            $currentTime = gmdate('Y-m-d H:i:s');
            
            // Calculate syndicate_after as current time + configured delay (in UTC)
            $syndicateAfter = gmdate('Y-m-d H:i:s', strtotime($currentTime . ' +' . $delay . ' minutes'));
            
            // Update using the Kirby database update method
            $values = [
                'ignored' => 0,
                'syndicate_after' => (string)$syndicateAfter,
                'syndicated_at' => null,
                'syndicated_url' => null,
                'updated_at' => (string)gmdate('Y-m-d H:i:s')
            ];
            
            // Make sure all values are the correct type
            foreach ($values as $key => $value) {
                if (!is_string($value) && !is_int($value) && !is_null($value)) {
                    $values[$key] = (string)$value;
                }
            }
            
            $result = $this->db->table('syndications')
                ->where('id', '=', (int)$id)
                ->update($values);
            
            return $result;
        } catch (\Exception $e) {
            error_log('Database: Error in unignoreItem: ' . $e->getMessage());
            throw new Exception("Failed to unignore item: " . $e->getMessage());
        }
    }

    /**
     * Get all syndications ready to be processed
     * (not syndicated, not ignored, and past the syndicate_after time)
     */
    public function getReadyForSyndication()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        try {
            // Use UTC time for consistency
            $currentTime = gmdate('Y-m-d H:i:s');
            
            // Use plain SQLite query through PDO for reliability
            $pdo = $this->db->connection();
            $stmt = $pdo->prepare("SELECT * FROM syndications WHERE (syndicated_at IS NULL OR syndicated_at = '') AND ignored = 0 AND syndicate_after <= ? ORDER BY syndicate_after ASC");
            $stmt->execute([$currentTime]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Ensure we return an array
            if (!is_array($result) || is_bool($result)) {
                return [];
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log('Database: Error in getReadyForSyndication: ' . $e->getMessage());
            throw new Exception("Failed to get ready syndications: " . $e->getMessage());
        }
    }
}