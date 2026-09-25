<?php

namespace Notmyhostname\Posse\Models\Services;

use Kirby\Cms\Page;
use Notmyhostname\Posse\Models\Database;
use Notmyhostname\Posse\Models\Config;
use swentel\nostr\Key\Key;
use swentel\nostr\Event\Event;
use swentel\nostr\Nip19\Nip19Helper;
use swentel\nostr\RelayResponse\RelayResponse;
use swentel\nostr\RelayResponse\RelayResponseOk;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Message\EventMessage;
use WebSocket\Client;
use WebSocket\Message\Text;

class NostrService extends AbstractService implements ServiceInterface
{
    private $key;

    public function __construct(Database $db, Config $config)
    {
        parent::__construct('nostr', $db, $config);
        $this->key = new Key();
    }

    /**
     * Get the service name
     */
    public function getName(): string
    {
        return 'nostr';
    }

    /**
     * Get the service display name
     */
    public function getDisplayName(): string
    {
        return 'Nostr';
    }

    /**
     * Get the service description
     */
    public function getDescription(): string
    {
        return 'Syndicate your content to Nostr';
    }

    /**
     * Get the service configuration fields
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enable Nostr',
                'default' => false
            ],
            'private_key' => [
                'type' => 'text',
                'label' => 'Private Key',
                'help' => 'Your Nostr private key (nsec...)',
                'required' => true
            ],
            'relay_urls' => [
                'type' => 'text',
                'label' => 'Relay URLs',
                'help' => 'Nostr relay URLs (e.g., wss://relay.damus.io)',
                'required' => true
            ],
            'frontend_url' => [
                'type' => 'text',
                'label' => 'Frontend URL',
                'help' => 'URL for viewing posts (e.g., https://primal.net/e/ for Primal, https://snort.social/e/ for Snort). This is where your nevent IDs will be displayed.',
                'default' => 'https://primal.net/e/',
                'required' => true
            ]
        ];
    }

    /**
     * Syndicate a page to Nostr
     */
    public function syndicate($item, Page $page, string $content): array
    {
        try {
            // Get service configuration
            if (!$this->serviceConfig['enabled']) {
                throw new \Exception('Nostr service is not enabled');
            }

            // Get the private key and relay URLs
            $privateKey = $this->serviceConfig['private_key'] ?? '';
            $relayUrls = $this->serviceConfig['relay_urls'] ?? null;
            $frontendUrl = $this->serviceConfig['frontend_url'] ?? 'https://primal.net/e/';
            
            // Convert string to array if needed (in case it's comma-separated)
            if (is_string($relayUrls)) {
                $relayUrls = array_map('trim', explode(',', $relayUrls));
            } elseif (!is_array($relayUrls)) {
                $relayUrls = null;
            }

            if (empty($privateKey)) {
                throw new \Exception('Nostr private key is not configured');
            }
            
            if (empty($relayUrls)) {
                return [
                    'status' => 'error',
                    'message' => 'Nostr relay URLs are not configured. Please update the <a href="/panel/site?tab=posse">POSSE settings</a>.'
                ];
            }

            // Convert private key from bech32 to hex if needed
            if (strpos($privateKey, 'nsec') === 0) {
                $privateKey = $this->key->convertToHex($privateKey);
            }

            // Get the public key from the private key
            $key = new Key();
            // Suppress deprecation warning for ECC library
            $publicKey = @$key->getPublicKey($privateKey);
            
            // Create the Nostr event
            $event = new Event();
            $event->setKind(1); // Text note
            $event->setContent($content);
            $event->setCreatedAt(time());
            $event->setPublicKey($publicKey);
            
            // Add tag for the page URL
            $event->addTag(['r', $page->url()]);
            
            // Process image if available
            $images = $this->prepareImagesForSyndication($page, 1);
            if (count($images) > 0) {
                $image = $images[0];
                
                // Apply the same image preset sizing as Mastodon/Bluesky
                $useOriginal = $this->config->option('use_original_image_size', false);
                $preset = $this->config->option('image_preset', '1800w');
                
                if ($useOriginal) {
                    $imageFile = $image;
                } else {
                    $presetName = $this->getThumbPreset($preset);
                    // Get preset options and force JPEG for Nostr client compatibility
                    $presetOptions = kirby()->option('thumbs.presets.' . $presetName, ['width' => 1800]);
                    $presetOptions['format'] = 'jpg';
                    $thumb = $image->thumb($presetOptions);
                    $imageFile = $thumb->exists() ? $thumb->save() : $image;
                }

                $imageUrl = $imageFile->url();

                // Append image URL to content so clients render it inline
                $event->setContent($event->getContent() . "\n\n" . $imageUrl);

                // Prepare imeta tag for the image (NIP-92)
                $imetaTag = ['imeta'];
                
                // Add URL
                $imetaTag[] = 'url ' . $imageUrl;
                
                // Add MIME type
                $mimeType = $imageFile->mime();
                if ($mimeType) {
                    $imetaTag[] = 'm ' . $mimeType;
                }
                
                // Add dimensions
                $width = $imageFile->width();
                $height = $imageFile->height();
                if ($width && $height) {
                    $imetaTag[] = 'dim ' . $width . 'x' . $height;
                }
                
                // Add alt text if available
                $altText = '';
                if ($image->alt()->exists() && $image->alt()->isNotEmpty()) {
                    $altText = $image->alt()->value();
                } elseif ($page->title()->exists() && $page->title()->isNotEmpty()) {
                    $altText = $page->title()->value();
                }
                
                if (!empty($altText)) {
                    $imetaTag[] = 'alt ' . $altText;
                }
                
                // Add the imeta tag
                $event->addTag($imetaTag);
                
                error_log('POSSE Plugin: Added image to Nostr event: ' . $imageUrl);
            }
            
            // Sign the event
            $sign = new Sign();
            @$sign->signEvent($event, $privateKey);

            // Verify the event before sending
            if (!@$event->verify()) {
                throw new \Exception('Failed to verify Nostr event');
            }

            // RelaySet::send() aborts on the first relay that throws and ignores the OK status, so publish to each relay on its own
            $payload = (new EventMessage($event))->generate();
            error_log('POSSE Plugin: Attempting to connect to Nostr relays: ' . implode(', ', $relayUrls));
            $startTime = microtime(true);

            $acceptedRelays = [];
            foreach ($relayUrls as $relayUrl) {
                try {
                    $ok = $this->publishToRelay($relayUrl, $payload, $event->getId());
                } catch (\Throwable $e) {
                    error_log('POSSE Plugin: Nostr relay ' . $relayUrl . ' failed: ' . $e->getMessage());
                    continue;
                }
                if (!$ok->status) {
                    error_log('POSSE Plugin: Nostr relay ' . $relayUrl . ' rejected event: ' . $ok->message);
                    continue;
                }
                $acceptedRelays[] = $relayUrl;
            }

            $duration = round(microtime(true) - $startTime, 2);
            error_log('POSSE Plugin: Relay connection attempts completed in ' . $duration . ' seconds');

            if (empty($acceptedRelays)) {
                throw new \Exception('No Nostr relay accepted the event');
            }

            error_log('POSSE Plugin: Nostr event accepted by: ' . implode(', ', $acceptedRelays));

            $debugNote = [
                'content' => $event->getContent(),
                'kind' => $event->getKind(),
                'tags' => $event->getTags(),
                'created_at' => $event->getCreatedAt(),
                'pubkey' => $event->getPublicKey(),
                'id' => $event->getId()
            ];
            error_log('POSSE Plugin: Full Nostr note sent: ' . json_encode($debugNote, JSON_PRETTY_PRINT));

            // Relay hints let clients find the note on relays that actually stored it
            $nevent = (new Nip19Helper())->encodeEvent($event, $acceptedRelays, $publicKey, 1);
            $viewableUrl = $frontendUrl . $nevent;

            return [
                'status' => 'success',
                'message' => 'Successfully syndicated to Nostr',
                'syndicated_url' => $viewableUrl
            ];

        } catch (\Exception $e) {
            error_log('POSSE Plugin: Nostr syndication error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send the event to one relay and wait for its OK response
     */
    private function publishToRelay(string $url, string $payload, string $eventId): RelayResponseOk
    {
        $client = new Client($url);
        $client->setTimeout(10);
        try {
            $client->text($payload);
            // Relays may send NOTICE, AUTH or pings before the OK
            for ($i = 0; $i < 10; $i++) {
                $message = $client->receive();
                if (!$message instanceof Text) {
                    continue;
                }
                $response = RelayResponse::create(json_decode($message->getContent()));
                if ($response instanceof RelayResponseOk && $response->eventId === $eventId) {
                    return $response;
                }
            }
            throw new \Exception('no OK response received');
        } finally {
            $client->disconnect();
        }
    }
    
    /**
     * Convert a nostr: URL to configured frontend format
     */
    public function convertNostrUrl(string $nostrUrl): string
    {
        $frontendUrl = $this->serviceConfig['frontend_url'] ?? 'https://primal.net/e/';
        
        // Check if it's already a frontend URL
        if (strpos($nostrUrl, $frontendUrl) === 0) {
            return $nostrUrl;
        }
        
        // Check if it's a nostr: URL
        if (strpos($nostrUrl, 'nostr:') === 0) {
            $hexEventId = substr($nostrUrl, 6); // Remove 'nostr:' prefix
            
            return $frontendUrl . $hexEventId;
        }
        
        // If it's already a hex ID without prefix, use it directly
        if (preg_match('/^[a-fA-F0-9]{64}$/', $nostrUrl)) {
            return $frontendUrl . $nostrUrl;
        }
        
        // Return as-is if we can't convert it
        return $nostrUrl;
    }
    
    /**
     * Update existing nostr: URLs in the database to Primal format
     */
    public function updateExistingUrls(): array
    {
        try {
            $updated = 0;
            $errors = [];
            
            // Get all Nostr syndications with old format URLs
            $nostrItems = $this->db->getQueue(['service' => 'nostr', 'syndicated' => true]);
            
            foreach ($nostrItems as $item) {
                if (strpos($item->syndicated_url, 'nostr:') === 0) {
                    try {
                        $newUrl = $this->convertNostrUrl($item->syndicated_url);
                        
                        // Update the database
                        $this->db->markSyndicated($item->id, $newUrl);
                        $updated++;
                        
                        error_log('POSSE Plugin: Updated Nostr URL from ' . $item->syndicated_url . ' to ' . $newUrl);
                    } catch (\Exception $e) {
                        $errors[] = 'Failed to update item ' . $item->id . ': ' . $e->getMessage();
                    }
                }
            }
            
            return [
                'status' => 'success',
                'updated' => $updated,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to update existing URLs: ' . $e->getMessage()
            ];
        }
    }
} 