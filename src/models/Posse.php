<?php

namespace Notmyhostname\Posse\Models;

use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Notmyhostname\Posse\Models\Services\MastodonService;
use Notmyhostname\Posse\Models\Services\BlueskyService;

class Posse
{
    protected $db;
    protected $config;
    
    public function __construct()
    {
        $this->db = new Database();
        $this->config = new Config();
    }
    
    /**
     * Handle page creation/update 
     * Adds page to syndication queue if it's a configured content type
     */
    public function handlePage(Page $page)
    {
        // Only process published pages
        if ($page->isListed()) {
            // Check if this is a tracking content type using template or intendedTemplate
            $template = $page->template()->name();
            $intendedTemplate = $page->intendedTemplate()->name();
            
            // Get content types to track from config
            $contentTypes = $this->config->option('contenttypes', []);
            
            // Check if the template is one we should track
            $isValidType = false;
            foreach ($contentTypes as $type => $enabled) {
                if ($enabled && ($template === $type || $intendedTemplate === $type)) {
                    $isValidType = true;
                    break;
                }
            }
            
            if (!$isValidType) {
                error_log('POSSE Plugin: Ignoring page type ' . $template . ' - not configured for syndication');
                return;
            }

            // Check for date field using Kirby's field API
            $hasDate = $page->date()->exists() || $page->published()->exists();
            if (!$hasDate) {
                error_log('POSSE Plugin: Ignoring page - no date field found');
                return;
            }

            // Add to queue for each enabled service
            $enabledServices = $this->getEnabledServices();
            // Add explicit null check for $enabledServices before using count()
            if (!is_array($enabledServices) || empty($enabledServices)) {
                error_log('POSSE Plugin: No enabled services found in configuration');
                return;
            }
            
            foreach ($enabledServices as $service) {
                $this->addToQueue($page, $service);
            }
        }
    }
    
    /**
     * Add a page to the syndication queue
     */
    public function addToQueue(Page $page, string $service)
    {
        // Verify the page has date information
        if (!$page->date()->exists() && !$page->published()->exists()) {
            error_log('Posse: Warning - page has no date or published field');
        }
        
        // All Kirby pages have UUIDs, so we don't need to check
        
        // Add to queue (processing happens at syndication time)
        return $this->db->addToQueue($page, $service);
    }
    
    /**
     * Get all available services
     */
    public function getAvailableServices(): array
    {
        $services = $this->config->option('services', []);
        return array_keys($services);
    }
    
    /**
     * Get only enabled services based on config
     */
    public function getEnabledServices(): array
    {
        $services = $this->config->option('services', []);
        $enabledServices = [];
        
        foreach ($services as $service => $config) {
            if (isset($config['enabled']) && $config['enabled'] === true) {
                $enabledServices[] = $service;
            }
        }
        
        return $enabledServices;
    }
    
    /**
     * Get items in the syndication queue
     */
    public function getQueue(array $options = []): array
    {
        return $this->db->getQueue($options);
    }
    
    /**
     * Get items ready for syndication
     */
    public function getReadyItems(): array
    {
        return $this->db->getReadyForSyndication();
    }
    
    /**
     * Mark an item as syndicated
     */
    public function markSyndicated($id, string $syndicatedUrl): bool
    {
        // Convert ID to int to avoid type errors
        $id = (int)$id;
        
        // Get the item from the database to retrieve the page_uuid and service
        $item = $this->db->getSyndication($id);
        if ($item) {
            // Find the page using UUID
            $page = \Kirby\Uuid\Uuid::for('page://' . $item->page_uuid)->model();
            // Store the syndicated URL in the page content
            $this->storeSyndicatedUrlInPage($page, $item->service, $syndicatedUrl);
        }
        
        // Mark the item as syndicated in the database
        return $this->db->markSyndicated($id, $syndicatedUrl);
    }
    
    /**
     * Mark an item as ignored
     */
    public function markIgnored($id, bool $ignored = true): bool
    {
        // Convert ID to int to avoid type errors
        $id = (int)$id;
        return $this->db->markIgnored($id, $ignored);
    }
    
    /**
     * Unignore an item and set it back to the queue
     */
    public function unignoreItem($id, int $delay = 60): bool
    {
        // Convert ID to int to avoid type errors
        $id = (int)$id;
        return $this->db->unignoreItem($id, $delay);
    }
    
    /**
     * Get a single syndication item by ID
     */
    public function getSyndication(int $id)
    {
        return $this->db->getSyndication($id);
    }
    
    /**
     * Process a template with page content
     */
    protected function processTemplate(Page $page, ?string $service = null): string
    {
        // Prepare variables for the snippet
        $data = [
            'page' => $page,
            'title' => $page->title()->value(),
            'url' => $page->url(),
            'date' => $page->date()->exists() ? $page->date()->toDate('Y-m-d') : date('Y-m-d')
        ];
        
        // Add tags if available
        if ($page->tags()->exists() && $page->tags()->isNotEmpty()) {
            $tags = $page->tags()->split();
            $hashTags = array_map(function($tag) {
                // Clean the tag (remove spaces, special chars)
                $tag = preg_replace('/[^a-zA-Z0-9]/', '', $tag);
                return '#' . $tag;
            }, $tags);
            
            $data['tags'] = $hashTags;
        } else {
            $data['tags'] = [];
        }
        
        // Try to load service-specific snippet first
        if ($service) {
            $snippetPath = 'posse/' . $service;
            $snippet = snippet($snippetPath, $data, true);
            if ($snippet) {
                return $this->normalizeNewlines($snippet);
            }
        }
        
        // Fall back to default snippet
        $defaultSnippet = snippet('posse/default', $data, true);
        
        if (!$defaultSnippet) {
            throw new \Exception('No template found for POSSE syndication. Neither service-specific nor default template could be loaded.');
        }
        
        return $this->normalizeNewlines($defaultSnippet);
    }
    
    /**
     * Normalize newlines in content
     * 
     * @param string $content The content to normalize
     * @return string Normalized content
     */
    protected function normalizeNewlines(string $content): string
    {
        // First convert any literal \n strings to actual newlines
        $content = str_replace('\n', "\n", $content);
        
        // Then convert any double-escaped newlines
        $content = str_replace('\\n', "\n", $content);
        
        // Normalize line endings to \n
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        
        // Remove excessive line breaks (more than 2 consecutive)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Ensure double line breaks between sections, but preserve actual newlines
        $content = preg_replace('/([^\n])\n([^\n])/', "$1\n\n$2", $content);
        
        // Trim whitespace while preserving internal line breaks
        return trim($content);
    }
    
    /**
     * Syndicate an item immediately
     * 
     * @param string $id The ID of the item to syndicate
     * @return array Response with status and syndicated URL
     */
    public function syndicateNow($id): array
    {
        // Convert ID to int to avoid type errors
        $id = (int)$id;
        
        // Get the item from the database
        $item = $this->db->getSyndication($id);
        
        if (!$item) {
            throw new \Exception("Item not found in syndication queue");
        }
        
        // Check if already syndicated
        if ($item->syndicated_at) {
            return [
                'status' => 'error',
                'message' => 'Already syndicated at ' . $item->syndicated_at
            ];
        }
        
        // Check if ignored
        if ($item->ignored) {
            return [
                'status' => 'error',
                'message' => 'Item is marked as ignored'
            ];
        }
        
        // Get the page using UUID - will throw an exception if not found
        $page = \Kirby\Uuid\Uuid::for('page://' . $item->page_uuid)->model();
        error_log('POSSE Plugin: Syndicating page: ' . $page->title()->value());
        error_log('POSSE Plugin: Service: ' . $item->service);
        
        // Process the template using the service name
        $content = $this->processTemplate($page, $item->service);
        error_log('POSSE Plugin: Processed content: ' . $content);
        
        // Get the service configuration
        $serviceConfig = $this->config->option('services', [])[$item->service] ?? null;
        
        if (!$serviceConfig || !isset($serviceConfig['enabled']) || !$serviceConfig['enabled']) {
            return [
                'status' => 'error',
                'message' => 'Service is not enabled: ' . $item->service
            ];
        }
        
        // Initialize the appropriate service and syndicate
        switch ($item->service) {
            case 'mastodon':
                $service = new MastodonService($this->db, $this->config);
                return $service->syndicate($item, $page, $content);
                
            case 'bluesky':
                $service = new BlueskyService($this->db, $this->config);
                return $service->syndicate($item, $page, $content);
                
            default:
                return [
                    'status' => 'error',
                    'message' => 'Unknown service: ' . $item->service
                ];
        }
    }
    
    /**
     * Store the syndicated URL in the page content
     * 
     * @param Page $page The page to update
     * @param string $service The service name
     * @param string $url The syndicated URL
     * @return bool Success status
     */
    public function storeSyndicatedUrlInPage(Page $page, string $service, string $url): bool
    {
        try {
            // Get existing URLs or create a new empty array
            $existingUrls = [];
            
            if ($page->syndicated_urls()->isNotEmpty()) {
                // This is a structure field, so we need to extract URLs differently
                $structure = $page->syndicated_urls()->toStructure();
                
                if ($structure->count() > 0) {
                    foreach ($structure as $item) {
                        if ($item->url()->isNotEmpty()) {
                            $existingUrls[] = $item->url()->value();
                        }
                    }
                }
            }
            
            // Check if our new URL already exists in the list
            if (!in_array($url, $existingUrls)) {
                // URL is not in the list, so add it
                $existingUrls[] = $url;
                
                try {
                    // Create a properly structured array for the syndicated_urls field
                    $structuredUrls = [];
                    foreach ($existingUrls as $urlItem) {
                        $structuredUrls[] = ['url' => $urlItem];
                    }
                    
                    // Convert the structured array to YAML format
                    $structuredYaml = Yaml::encode($structuredUrls);
                    
                    // Update the page with the new URL added to the list, using structured format
                    try {
                        $page->update([
                            'syndicated_urls' => $structuredYaml  // Use underscore version to match blueprint
                        ]);
                        return true; // Successfully updated
                    } catch (\Exception $e) {
                        error_log('POSSE Plugin: Failed to update page with syndicated URL: ' . $e->getMessage());
                        return false;
                    }
                } catch (\Exception $e) {
                    error_log('POSSE Plugin: Failed to update page with syndicated URL: ' . $e->getMessage());
                    return false;
                }
            } else {
                // URL already exists, no changes needed
                return true;
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error storing syndicated URL in page: ' . $e->getMessage());
            return false;
        }
    }
}