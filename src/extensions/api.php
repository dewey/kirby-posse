<?php

/**
 * POSSE API Routes
 * 
 * Defines the API endpoints for the POSSE plugin
 */

use Notmyhostname\Posse\Models\Posse;
use Notmyhostname\Posse\Models\Config;
use Notmyhostname\Posse\Middleware\Auth;

return [
    'routes' => [
        [
            'pattern' => 'posse/queue',
            'method' => 'GET',
            'auth' => true,
            'action' => function () {
                try {
                    $posse = new Posse();
                    
                    // First get pending queue items (non-syndicated and non-ignored)
                    $options = kirby()->request()->get();
                    $pendingItems = $posse->getQueue($options);
                    
                    // Then get syndicated or ignored items for the history table (set syndicated=true)
                    $historyOptions = array_merge(kirby()->request()->get(), ['syndicated' => true]);
                    $syndicatedItems = $posse->getQueue($historyOptions);
                    
                    // Combine both result sets
                    $result = array_merge($pendingItems, $syndicatedItems);
                    
                    // Add panel URL to each item using Kirby's panel() method
                    foreach ($result as &$item) {
                        if (!empty($item->page_uuid)) {
                            try {
                                // Get the page object using the UUID
                                $page = \Kirby\Uuid\Uuid::for('page://' . $item->page_uuid)->model();
                                
                                // Add the panel URL to the item
                                if ($page) {
                                    $item->panel_url = $page->panel()->url();
                                }
                            } catch (\Exception $pageError) {
                                // If we can't get the page, don't add the panel URL
                                error_log('POSSE Plugin: Error getting page for UUID ' . $item->page_uuid . ': ' . $pageError->getMessage());
                            }
                        }
                    }
                    
                    return $result;
                } catch (\Exception $e) {
                    // Log the error for debugging
                    error_log('POSSE Plugin Error: ' . $e->getMessage());
                    error_log('POSSE Plugin Error Stack: ' . $e->getTraceAsString());

                    // Return empty array instead of error to prevent Vue component failure
                    return [];
                }
            }
        ],
        [
            'pattern' => 'posse/mark-syndicated',
            'method' => 'POST',
            'auth' => true,
            'action' => function () {
                try {
                    $posse = new Posse();

                    // Get data from both input methods to be more robust
                    $input = file_get_contents('php://input');
                    $inputData = json_decode($input, true) ?: [];
                    $postData = kirby()->request()->data() ?: [];

                    // Merge data sources with POST taking precedence
                    $data = array_merge($inputData, $postData);

                    if (!isset($data['id']) || !isset($data['syndicated_url'])) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing required parameters'
                        ];
                    }

                    $result = $posse->markSyndicated($data['id'], $data['syndicated_url']);

                    return [
                        'status' => $result ? 'success' : 'error'
                    ];
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'An error occurred: ' . $e->getMessage()
                    ];
                }
            }
        ],
        [
            'pattern' => 'posse/mark-ignored',
            'method' => 'POST',
            'auth' => true,
            'action' => function () {
                try {
                    $posse = new Posse();

                    // Get data from both input methods to be more robust
                    $input = file_get_contents('php://input');
                    $inputData = json_decode($input, true) ?: [];
                    $postData = kirby()->request()->data() ?: [];

                    // Merge data sources with POST taking precedence
                    $data = array_merge($inputData, $postData);

                    if (!isset($data['id'])) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing required parameter: id'
                        ];
                    }

                    $ignored = $data['ignored'] ?? true;
                    $result = $posse->markIgnored($data['id'], $ignored);

                    return [
                        'status' => $result ? 'success' : 'error'
                    ];
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'An error occurred: ' . $e->getMessage()
                    ];
                }
            }
        ],
        [
            'pattern' => 'posse/unignore',
            'method'  => 'POST',
            'auth'    => true,
            'action'  => function () {
                try {
                    $posse = new Posse();

                    // Get data from both input methods to be more robust
                    $input = file_get_contents('php://input');
                    $inputData = json_decode($input, true) ?: [];
                    $postData = kirby()->request()->data() ?: [];

                    // Merge data sources with POST taking precedence
                    $data = array_merge($inputData, $postData);

                    if (!isset($data['id'])) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing required parameter: id'
                        ];
                    }

                    // Get the delay from the request or use default (60 minutes)
                    $delay = isset($data['delay']) ? intval($data['delay']) : 60;
                    
                    // Use the Database model's unignoreItem method
                    $result = $posse->unignoreItem($data['id'], $delay);
                    
                    if (!$result) {
                        return [
                            'status' => 'error',
                            'message' => 'Failed to unignore item'
                        ];
                    }
                    return [
                        'status' => 'success',
                        'message' => 'Item unignored and added back to queue'
                    ];
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'An error occurred: ' . $e->getMessage()
                    ];
                }
            }
        ],
        [
            'pattern' => 'posse/posts',
            'method' => 'GET',
            'auth' => true,
            'action' => function () {
                try {
                    $items = [];
                    $config = new Config();
                    $contentTypes = $config->option('contenttypes', []);
                    
                    // Get the enabled services to check for syndication
                    $services = $config->option('services', []);
                    $enabledServices = [];
                    foreach ($services as $service => $serviceConfig) {
                        if (isset($serviceConfig['enabled']) && $serviceConfig['enabled'] === true) {
                            $enabledServices[] = $service;
                        }
                    }
                    
                    // Get all pages that are listed (published)
                    $allListedPages = site()->index()->listed();
                    
                    // Process each content type from config
                    // If no content types are configured, return empty array early
                    if (empty($contentTypes)) {
                        return [];
                    }
                    
                    foreach ($contentTypes as $templateName => $enabled) {
                        // Skip disabled content types
                        if (!$enabled) {
                                continue;
                        }
                        
                        // Use Kirby's filter API to find pages with this template
                        $templatePages = $allListedPages->filterBy(function($page) use ($templateName) {
                            // Check both template and intendedTemplate for flexibility
                            $currentTemplate = $page->template()->name();
                            $intendedTemplate = $page->intendedTemplate()->name();
                            
                            return ($currentTemplate === $templateName || $intendedTemplate === $templateName);
                        });
                        
                        
                        // Add each page to the items array
                        foreach ($templatePages as $page) {
                            // Skip pages that are already syndicated to all enabled services
                            if (count($enabledServices) > 0) {
                                $syndicatedUrls = [];
                                
                                // Check if the page has syndicated_urls field
                                if ($page->syndicated_urls()->isNotEmpty()) {
                                    // Get the syndicated URLs
                                    $syndicatedUrls = $page->syndicated_urls()->yaml();
                                    
                                    if (!is_array($syndicatedUrls)) {
                                        $syndicatedUrls = [];
                                    }
                                }
                                
                                // Check if all enabled services are already syndicated
                                $syndicatedCount = 0;
                                
                                // Get count of syndicated items in the database
                                try {
                                    $db = new \Notmyhostname\Posse\Models\Database();
                                    
                                    foreach ($enabledServices as $service) {
                                        // Check if there's an existing record for this page and service
                                        $existingQuery = $db->getDatabase()->table('syndications')
                                            ->where('page_id', '=', $page->id())
                                            ->where('service', '=', $service)
                                            ->where(function($query) {
                                                $query->where('syndicated_at', 'IS NOT', null)
                                                    ->orWhere('ignored', '=', 1);
                                            })
                                            ->first();
                                        
                                        if ($existingQuery) {
                                            $syndicatedCount++;
                                        }
                                    }
                                    
                                    // If all services are already syndicated, skip this page
                                    if ($syndicatedCount >= count($enabledServices)) {
                                        continue;
                                    }
                                } catch (\Exception $e) {
                                    error_log('POSSE Plugin: Error checking syndication status: ' . $e->getMessage());
                                    // Continue with adding the page even if we can't check the status
                                }
                            }
                            
                            // Get published date from date field if available
                            $published = null;
                            if ($page->date()->exists()) {
                                $published = $page->date()->toDate();
                            } elseif ($page->published()->exists()) {
                                $published = $page->published()->toDate();
                            }
                            
                            $items[] = [
                                'id' => $page->id(),
                                'title' => $page->title()->value() ?: $page->slug(),
                                'type' => $templateName,
                                'url' => $page->url(),
                                'published' => $published,
                            ];
                        }
                    }
                    
                    // Sort by published date, newest first if available
                    usort($items, function($a, $b) {
                        if (!$a['published'] || !$b['published']) {
                            return 0;
                        }
                        return strtotime($b['published']) - strtotime($a['published']);
                    });

                    return $items;
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Error fetching posts: ' . $e->getMessage());
                    return [];
                }
            }
        ],
        [
            'pattern' => 'posse/add-to-queue',
            'method' => 'POST',
            'auth' => true,
            'action' => function () {
                try {
                    // Get request data using multiple methods to be more robust
                    $input = file_get_contents('php://input');
                    $jsonData = json_decode($input, true) ?: [];
                    $requestData = kirby()->request()->data() ?: [];

                    // Merge data from different sources
                    $data = array_merge($jsonData, $requestData);

                    // Basic validation
                    if (empty($data) || !isset($data['page_id']) || !isset($data['service'])) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing required parameters page_id and service',
                            'received' => $data
                        ];
                    }

                    // Find the page
                    $page = page($data['page_id']);
                    if (!$page) {
                        return [
                            'status' => 'error',
                            'message' => 'Page not found: ' . $data['page_id']
                        ];
                    }

                    // Add to queue
                    try {
                        $posse = new Posse();
                        $result = $posse->addToQueue($page, $data['service']);

                        if ($result) {
                            return [
                                'status' => 'success',
                                'message' => 'Page added to queue'
                            ];
                        } else {
                            return [
                                'status' => 'error',
                                'message' => 'Failed to add page to queue'
                            ];
                        }
                    } catch (\Exception $e) {
                        $errorMessage = $e->getMessage();
                        error_log('POSSE Plugin: Error adding to queue: ' . $errorMessage);

                        // Provide user-friendly error message
                        if (strpos($errorMessage, 'database') !== false || strpos($errorMessage, 'table') !== false) {
                            $userMessage = 'Database error: ' . $errorMessage;
                        } else if (strpos($errorMessage, 'type') !== false) {
                            $userMessage = 'Data type error: ' . $errorMessage;
                        } else {
                            $userMessage = 'Error adding to queue: ' . $errorMessage;
                        }

                        return [
                            'status' => 'error',
                            'message' => $userMessage
                        ];
                    }
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'An error occurred: ' . $e->getMessage()
                    ];
                }
            }
        ],
        // Syndicate Now endpoint
        [
            'pattern' => 'posse/syndicate-now',
            'method' => 'POST',
            'auth' => true,
            'action' => function () {
                try {
                    $posse = new Posse();

                    // Get data from both input methods to be more robust
                    $input = file_get_contents('php://input');
                    $inputData = json_decode($input, true) ?: [];
                    $postData = kirby()->request()->data() ?: [];

                    // Merge data sources with POST taking precedence
                    $data = array_merge($inputData, $postData);

                    if (!isset($data['id'])) {
                        return [
                            'status' => 'error',
                            'message' => 'Missing required parameter: id'
                        ];
                    }

                    // Process the syndication
                    $result = $posse->syndicateNow($data['id']);
                    
                    // Return the result
                    return $result;
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'An error occurred: ' . $e->getMessage()
                    ];
                }
            }
        ],
        // Settings API endpoints
        [
            'pattern' => 'posse/settings',
            'method' => 'GET',
            'auth' => true,
            'action' => function () {
                try {
                    // Use the Config model to get settings
                    $config = new Config();
                    return $config->get();
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Settings Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'Failed to load settings: ' . $e->getMessage()
                    ];
                }
            }
        ],
        [
            'pattern' => 'posse/settings',
            'method' => 'POST',
            'auth' => true,
            'action' => function () {
                try {
                    // Get request data
                    $input = file_get_contents('php://input');
                    $data = json_decode($input, true) ?: [];
                    
                    // Basic validation
                    if (empty($data)) {
                        return [
                            'status' => 'error',
                            'message' => 'No settings data provided'
                        ];
                    }

                    // Validate token length if token auth is enabled
                    if (isset($data['auth']['enabled']) && $data['auth']['enabled'] === true) {
                        if (empty($data['auth']['token']) || strlen($data['auth']['token']) < 8) {
                            return [
                                'status' => 'error',
                                'message' => 'The token must be at least 8 characters long'
                            ];
                        }
                    }
                    
                    // Save settings
                    $config = new Config();
                    $result = $config->save($data);
                    
                    if (!$result) {
                        return [
                            'status' => 'error',
                            'message' => 'Failed to save settings'
                        ];
                    }
                    
                    return [
                        'status' => 'success',
                        'message' => 'Settings saved successfully'
                    ];
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Settings Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'Failed to save settings: ' . $e->getMessage()
                    ];
                }
            }
        ],
        // Automated syndication endpoint for cron jobs
        [
            'pattern' => 'posse/cron-syndicate',
            'method' => 'GET',
            'auth' => false,
            'action' => function () {
                try {
                    // Apply token authentication for cron jobs
                    Auth::handle(kirby());
                    
                    $posse = new Posse();
                    
                    // Get items ready for syndication
                    $readyItems = $posse->getReadyItems();
                    
                    
                    // If no items are ready, return early
                    if (empty($readyItems)) {
                        return [
                            'status' => 'success',
                            'message' => 'No items ready for syndication',
                            'items_processed' => 0
                        ];
                    }
                    
                    // Get the current hour to ensure we only post one item per hour
                    // Always use UTC for consistency across the system
                    $currentHour = gmdate('YmdH');
                    
                    // Check if we've already posted in this hour by checking the database
                    $db = new \Notmyhostname\Posse\Models\Database();
                    $database = $db->getDatabase();
                    
                    // Query for posts syndicated in the current hour
                    $startOfHour = gmdate('Y-m-d H:00:00');
                    $endOfHour = gmdate('Y-m-d H:59:59');
                    
                    $recentlySyndicated = $database->table('syndications')
                        ->where('syndicated_at', '>=', $startOfHour)
                        ->where('syndicated_at', '<=', $endOfHour)
                        ->first();
                        
                    
                    // If we've already posted in this hour, don't process any more items
                    if ($recentlySyndicated) {
                        return [
                            'status' => 'success',
                            'message' => 'Already syndicated a post this hour',
                            'items_processed' => 0,
                            'next_check' => 'Next hour',
                            'last_syndicated' => $recentlySyndicated->syndicated_at
                        ];
                    }
                    
                    // Take the first item only (respecting one-per-hour limit)
                    $item = $readyItems[0];
                    
                    // Syndicate the item
                    $result = $posse->syndicateNow($item['id']);
                    
                    return [
                        'status' => $result['status'] ?? 'error',
                        'message' => $result['message'] ?? 'Unknown error',
                        'items_processed' => 1,
                        'item' => [
                            'id' => $item['id'],
                            'title' => $item['page_title'],
                            'service' => $item['service']
                        ]
                    ];
                } catch (\Exception $e) {
                    error_log('POSSE Plugin Cron Syndication Error: ' . $e->getMessage());
                    return [
                        'status' => 'error',
                        'message' => 'Failed to process cron syndication: ' . $e->getMessage(),
                        'items_processed' => 0
                    ];
                }
            }
        ]
    ]
];