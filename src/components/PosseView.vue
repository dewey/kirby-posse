<template>
  <k-inside>
    <k-view class="posse-view">
      <k-header>
        POSSE Queue
        <k-button-group slot="buttons">
          <k-button icon="add" @click="openAddDialog">Add to Queue</k-button>
          <k-button icon="settings" @click="siteSettings">Settings</k-button>
        </k-button-group>
      </k-header>

      <k-grid style="gap: 2.5rem">
        <k-column width="1/1">
          <div v-if="loading">
            <k-text>Loading syndication queue...</k-text>
          </div>

          <!-- Queue Table -->
          <custom-table
            :items="pendingItems"
            :posts="posts"
            :loading="loading"
            :postsLoading="postsLoading"
            :error="error"
            :syndicatingItems="syndicatingItems"
            @toggle-ignored="toggleIgnored"
            @add-to-queue="openAddDialog"
            @syndicate-now="syndicateNow"
          />

          <!-- History Table -->
          <history-table
            :items="syndicatedItems"
            :loading="loading"
            :error="error"
            @unignore="unignoreItem"
          />
        </k-column>
      </k-grid>

      <!-- Add to Queue Dialog -->
      <k-dialog
        ref="addDialog"
        @submit="submitAddDialog"
        @cancel="cancelAddDialog"
      >
        <!-- When there are no enabled services, show help text instead of form -->
        <div v-if="!hasEnabledServices">
          <p class="k-text">
            No syndication services are enabled. You need to enable at least one service in the settings.
          </p>
          <k-button @click="goToSettings" icon="settings">Go to Settings</k-button>
        </div>
        <!-- When services are enabled but no posts are available -->
        <p v-else-if="posts.length === 0" class="k-text">
          No eligible posts found. All posts have already been syndicated to all enabled services.
        </p>
        <!-- Normal form with both services and posts available -->
        <k-form
          v-else
          :fields="addDialogFields"
          v-model="addDialogData"
          @submit="submitAddDialog"
        />
      </k-dialog>

      <!-- Syndicated URL Dialog -->
      <k-dialog
        ref="urlDialog"
        @submit="submitUrlDialog"
        @cancel="cancelUrlDialog"
      >
        <k-form
          :fields="urlDialogFields"
          v-model="urlDialogData"
          @submit="submitUrlDialog"
        />
      </k-dialog>
    </k-view>
  </k-inside>
</template>

<script>
import CustomTable from './CustomTable.vue';
import HistoryTable from './HistoryTable.vue';

export default {
  components: {
    CustomTable,
    HistoryTable
  },
  // Accept CSRF token from Kirby
  props: {
    csrf: {
      type: String,
      required: false,
      default: ''
    }
  },
  data() {
    return {
      loading: true,
      postsLoading: true,
      settingsLoading: true,
      items: [],
      posts: [],
      error: null,
      syndicatingItems: [], // IDs of items currently being syndicated
      // Removed debug setting as it's no longer needed
      // Settings from config
      settings: {
        services: {
          mastodon: { enabled: false },
          bluesky: { enabled: false }
        },
        contenttypes: {
          post: true,
          photo: true
        }
      },
      // Add dialog state
      addDialogData: {
        page_id: "",
        service: ""
      },
      addDialogFields: {
        page_id: {
          label: "Select Post or Photo",
          type: "select",
          required: true,
          options: []
        },
        service: {
          label: "Services",
          type: "multiselect",
          required: true,
          max: null,
          options: []
        }
      },
      // URL dialog state for marking syndicated
      urlDialogData: {
        id: null,
        syndicated_url: ""
      },
      urlDialogFields: {
        syndicated_url: {
          label: "Syndicated URL",
          type: "url",
          required: true
        }
      },
      // Current item being processed
      currentItem: null,
      columns: {
        title: {
          label: "Page",
          type: "text",
          width: "1/4"
        },
        service: {
          label: "Service",
          type: "text",
          width: "1/5"
        },
        published: {
          label: "Published",
          type: "date",
          width: "1/5"
        },
        earliest: {
          label: "Syndicate After",
          type: "date",
          width: "1/5"
        },
        actions: {
          label: "Actions",
          type: "buttons",
          width: "1/5"
        }
      }
    };
  },
  computed: {
    // Check if any services are enabled
    hasEnabledServices() {
      if (!this.settings || !this.settings.services) return false;
      
      return Object.values(this.settings.services).some(config => config.enabled === true);
    },
    
    // Filter items that are pending (not syndicated and not ignored)
    pendingItems() {
      if (!this.items || !Array.isArray(this.items)) return [];
      
      return this.items.filter(item => {
        // Check if item has been syndicated
        const hasSyndicatedAt = item.syndicated_at && item.syndicated_at !== "null" && item.syndicated_at !== "";
        const hasSyndicationTime = item.syndication_time && item.syndication_time !== "null" && item.syndication_time !== "";
        
        // Check if item is ignored - using strict checking for different types
        const isIgnored = item.ignored == 1 || item.ignored === true || item.ignored === "1";
        
        // Only include items that are neither syndicated nor ignored
        return !(hasSyndicatedAt || hasSyndicationTime || isIgnored);
      });
    },
    
    // Filter items that are syndicated or ignored for history view
    syndicatedItems() {
      if (!this.items || !Array.isArray(this.items)) return [];
      
      const historyItems = this.items.filter(item => {
        // Check for string value "null" or empty strings as well
        const hasSyndicatedAt = item.syndicated_at && item.syndicated_at !== "null" && item.syndicated_at !== "";
        const hasSyndicationTime = item.syndication_time && item.syndication_time !== "null" && item.syndication_time !== "";
        const isIgnored = item.ignored === true || item.ignored === 1 || item.ignored === "1";
        
        // Include both syndicated items AND ignored items
        return (hasSyndicatedAt || hasSyndicationTime) || isIgnored;
      });
      
      return historyItems.sort((a, b) => {
        // For sorting, use updated_at if syndicated_at is not available (like for ignored items)
        const dateA = new Date(a.syndicated_at || a.syndication_time || a.updated_at);
        const dateB = new Date(b.syndicated_at || b.syndication_time || b.updated_at);
        return dateB - dateA;
      });
    },
    
    rows() {
      if (!this.items || !Array.isArray(this.items)) return [];
      
      return this.items.map(item => {
        const publishedDate = new Date(item.published_at || item.published_time);
        const syndicateAfter = new Date(item.syndicate_after || item.earliest_syndication_time);
        
        return {
          id: item.id,
          title: {
            text: item.page_title,
            link: item.panel_url || '#'
          },
          service: this.formatService(item.service),
          published: publishedDate.toLocaleString(),
          earliest: syndicateAfter.toLocaleString(),
          actions: [
            {
              icon: "url",
              text: "Mark Syndicated",
              click: this.markSyndicated.bind(this, item),
              disabled: !!(item.syndicated_at || item.syndication_time)
            },
            {
              icon: "cancel",
              text: item.ignored ? "Unignore" : "Ignore",
              click: this.toggleIgnored.bind(this, item),
              disabled: !!(item.syndicated_at || item.syndication_time)
            }
          ]
        };
      });
    },
    postOptions() {
      const options = this.posts.map(post => {
        return {
          value: post.id,
          text: `${post.title} (${post.type})`
        };
      });
      
      return options;
    },
    services() {
      // Filter to only show enabled services
      const enabledServices = [];
      
      if (this.settings && this.settings.services) {
        for (const [service, config] of Object.entries(this.settings.services)) {
          if (config.enabled) {
            enabledServices.push({
              value: service,
              text: this.formatService(service)
            });
          }
        }
      }
      
      // If no services are enabled, show all services but disabled
      if (enabledServices.length === 0) {
        return [
          { value: 'mastodon', text: 'Mastodon (disabled in settings)' },
          { value: 'bluesky', text: 'Bluesky (disabled in settings)' }
        ];
      }
      
      return enabledServices;
    },
    debugJSON() {
      return JSON.stringify({
        firstPost: this.posts.length > 0 ? this.posts[0] : null,
        firstQueueItem: this.items.length > 0 ? this.items[0] : null,
        allPosts: this.posts,
        allQueueItems: this.items
      }, null, 2);
    }
  },
  created() {
    // Initialize the component
    this.fetchSettings();
    this.fetchQueue();
    this.fetchPosts();
  },
  methods: {
    
    siteSettings() {
      this.$go('posse/settings');
    },
    
    goToSettings() {
      this.$refs.addDialog.close();
      this.$go('posse/settings');
    },
    
    async fetchQueue() {
      this.loading = true;
      this.error = null;

      try {
        // Use CSRF token from props (provided by Kirby PHP side)
        const csrfToken = this.csrf || "";

        // Use the fetch API with proper session authentication
        const response = await fetch('/api/posse/queue', {
          method: 'GET',
          headers: {
            'X-CSRF': csrfToken
          }
        });

        if (!response.ok) {
          throw new Error(`Error fetching queue: ${response.status}`);
        }

        const data = await response.json();

        // Check if response is valid
        if (data === null || data === undefined) {
          this.items = [];
          return;
        }

        // Check if response is an array
        if (Array.isArray(data)) {
          this.items = data;
        } else {
          console.warn("Unexpected response format from posse/queue", data);
          this.items = [];
        }
      } catch (error) {
        console.error("Error fetching syndication queue:", error);
        this.error = "Failed to load syndication queue. Check browser console for details.";
        this.items = [];
      } finally {
        this.loading = false;
      }
    },
    
    async fetchPosts() {
      this.postsLoading = true;
      try {
        // Use CSRF token from props (provided by Kirby PHP side)
        const csrfToken = this.csrf || "";

        // Use the fetch API with proper session authentication
        const response = await fetch('/api/posse/posts', {
          method: 'GET',
          headers: {
            'X-CSRF': csrfToken
          }
        });

        if (!response.ok) {
          throw new Error(`Error fetching posts: ${response.status}`);
        }

        const data = await response.json();

        if (Array.isArray(data)) {
          if (data.length === 0) {
            // Always log warnings
            console.warn("API returned empty posts array");
          }

          // Ensure all posts have the required id field
          const validPosts = data.filter(post => {
            if (!post.id) {
              // Always log errors
              console.error("Invalid post without id:", post);
              return false;
            }
            return true;
          });

          this.posts = validPosts;
        } else {
          console.warn("Unexpected posts response format:", data);
          this.posts = [];
        }
      } catch (error) {
        console.error("Error fetching posts:", error);
        this.$store.dispatch("notification/error", "Failed to load posts");
      } finally {
        this.postsLoading = false;
      }
    },
    
    formatService(service) {
      if (!service) return ''; 
      // Capitalize first letter
      return service.charAt(0).toUpperCase() + service.slice(1);
    },
    
    
    handleAction(action, row) {
      if (typeof action === 'function') {
        action(row);
      }
    },
    
    async openAddDialog() {
      // Fetch settings and posts before showing dialog
      await Promise.all([this.fetchSettings(), this.fetchPosts()]);
      
      // Even if we have no posts, we'll show the dialog with a message instead of an error notification
      // This provides a better user experience by explaining why no posts are available

      // Update the dialog fields with current posts
      this.addDialogFields.page_id.options = this.postOptions;
      
      // Update service options based on enabled services
      this.addDialogFields.service.options = this.services;
      
      // Select all enabled services by default
      const allEnabledServices = this.services.map(service => service.value);
      
      // Set initial dialog data
      this.addDialogData = {
        page_id: this.posts.length > 0 ? this.posts[0].id : "",
        service: allEnabledServices // Always an array for multiselect (will be empty if no services)
      };
      
      // If we have no posts, disable the form fields
      if (this.posts.length === 0) {
        this.addDialogFields.page_id.disabled = true;
        this.addDialogFields.service.disabled = true;
      } else {
        this.addDialogFields.page_id.disabled = false;
        this.addDialogFields.service.disabled = false;
      }

      // Determine the dialog text based on the conditions
      let dialogText = "";
      if (!this.hasEnabledServices) {
        dialogText = "No syndication services are enabled. Enable services in the settings to use the syndication queue.";
      } else if (this.posts.length === 0) {
        dialogText = "All eligible posts have already been syndicated to all enabled services.";
      } else {
        dialogText = "Select a post or photo to add to the syndication queue";
      }
      
      // Open the dialog
      this.$refs.addDialog.open({
        title: "Add to Queue",
        text: dialogText,
        button: "Add to Queue",
        size: "medium",
        submitButton: {
          text: "Add to Queue",
          disabled: this.posts.length === 0 || !this.hasEnabledServices
        }
      });
    },

    async submitAddDialog() {
      // Extract and validate form data
      const pageId = this.addDialogData.page_id;
      const services = this.addDialogData.service;

      if (!pageId || !services || !services.length) {
        this.$store.dispatch("notification/error", "Missing required values");
        return false;
      }

      try {
        // Use CSRF token from props (provided by Kirby PHP side)
        const csrfToken = this.csrf || "";
        const requests = [];
        
        // Create a promise for each service
        for (const service of services) {
          requests.push(
            fetch('/api/posse/add-to-queue', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF': csrfToken
              },
              body: JSON.stringify({
                page_id: pageId,
                service: service
              })
            }).then(response => response.json())
          );
        }
        
        // Wait for all requests to complete
        const results = await Promise.all(requests);
        
        // Check if all requests were successful
        const errors = results.filter(result => result.status === 'error');
        
        if (errors.length > 0) {
          // If any requests failed, show the first error
          throw new Error(errors[0].message || 'Failed to add to queue');
        }
        
        // Show success message with the number of services
        const servicesText = services.length > 1 ? `${services.length} services` : "service";
        this.$store.dispatch("notification/success", `Post added to syndication queue for ${servicesText}`);
        
        // Refresh the queue
        this.fetchQueue();

        // Close the dialog
        this.$refs.addDialog.close();
        return true;
      } catch (error) {
        console.error("Error adding to queue:", error);

        // Detailed error handling
        let errorMessage = error.message || "Unknown error";

        this.$store.dispatch("notification/error", "Failed to add to queue: " + errorMessage);
        return false;
      }
    },

    cancelAddDialog() {
      this.$refs.addDialog.close();
    },
    
    // Note: we've removed the mark-syndicated button from the UI, 
    // but keeping this method for potential future use
    async markSyndicated(item, syndicatedUrl) {
      if (!syndicatedUrl) {
        console.error("No syndicated URL provided");
        return;
      }

      // Store the current item
      this.currentItem = item;

      // Set URL dialog data
      this.urlDialogData = {
        id: item.id,
        syndicated_url: syndicatedUrl
      };

      // Submit the URL dialog without showing it to the user
      await this.submitUrlDialog();
    },

    async submitUrlDialog() {
      try {
        // Validate URL
        if (!this.urlDialogData.syndicated_url) {
          this.$store.dispatch("notification/error", "URL is required");
          return false;
        }

        // Use CSRF token from props (provided by Kirby PHP side)
        const csrfToken = this.csrf || "";

        // Use the fetch API with proper session authentication
        const response = await fetch('/api/posse/mark-syndicated', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF': csrfToken
          },
          body: JSON.stringify({
            id: this.urlDialogData.id,
            syndicated_url: this.urlDialogData.syndicated_url
          })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Failed to mark as syndicated');
        }

        // No notification needed here - the calling function will handle it
        this.fetchQueue();

        // Close the dialog
        this.$refs.urlDialog.close();
        return true;
      } catch (error) {
        console.error("Error marking as syndicated:", error);
        this.$store.dispatch("notification/error", "Failed to mark as syndicated: " + error.message);
        return false;
      }
    },

    cancelUrlDialog() {
      this.$refs.urlDialog.close();
    },
    
    async fetchSettings() {
      this.settingsLoading = true;
      try {
        const csrfToken = this.csrf || "";
        
        // Use fetch API with CSRF token
        const response = await fetch('/api/posse/settings', {
          method: 'GET',
          headers: {
            'X-CSRF': csrfToken
          }
        });
        
        if (!response.ok) {
          throw new Error(`Error loading settings: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Update settings with loaded data
        this.settings = data;
        
        // If no default service is selected, select all enabled services
        if ((!this.addDialogData.service || this.addDialogData.service.length === 0) && this.services.length > 0) {
          this.addDialogData.service = this.services.map(service => service.value);
        }
      } catch (error) {
        console.error("Error loading settings:", error);
        // Don't show error to user, just use defaults
      } finally {
        this.settingsLoading = false;
      }
    },
    
    // Simple direct method without dialog
    toggleIgnored(item) {
      // Add to processing array
      this.syndicatingItems.push(item.id);
      
      // Make API request to mark as ignored
      fetch('/api/posse/mark-ignored', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF': this.csrf || ''
        },
        body: JSON.stringify({
          id: item.id,
          ignored: true
        })
      })
      .then(response => response.json())
      .then(data => {
        // Show success message
        this.$store.dispatch('notification/success', 'Added to ignore list');
        // Refresh the queue
        this.fetchQueue();
      })
      .catch(error => {
        console.error('Error ignoring item:', error);
        this.$store.dispatch('notification/error', 'Failed to ignore item');
      })
      .finally(() => {
        // Remove from processing array
        const index = this.syndicatingItems.indexOf(item.id);
        if (index > -1) {
          this.syndicatingItems.splice(index, 1);
        }
      });
    },
    
    async syndicateNow(item) {
      // No confirmation dialog anymore - execute immediately
      try {
        // Add item to syndicating items
        this.syndicatingItems.push(item.id);

        // Use CSRF token from props
        const csrfToken = this.csrf || "";

        // Use the fetch API with proper session authentication
        const response = await fetch('/api/posse/syndicate-now', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF': csrfToken
          },
          body: JSON.stringify({
            id: item.id
          })
        });

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Failed to syndicate now');
        }

        // If a URL was returned, save it
        if (data.syndicated_url) {
          // Use our markSyndicated method to store the URL
          await this.markSyndicated(item, data.syndicated_url);
          
          // Show success message
          this.$store.dispatch("notification/success", "Successfully syndicated to " + item.service);
        } else {
          // Just show success message
          this.$store.dispatch("notification/success", "Post has been syndicated");
        }
        
        // Refresh the queue
        this.fetchQueue();
      } catch (error) {
        console.error("Error syndicating now:", error);
        this.$store.dispatch("notification/error", "Failed to syndicate: " + error.message);
      } finally {
        // Remove item from syndicating items
        const index = this.syndicatingItems.indexOf(item.id);
        if (index > -1) {
          this.syndicatingItems.splice(index, 1);
        }
      }
    },
    
    // Unignore an item and set it back to the queue
    async unignoreItem(item) {
      try {
        // Add to processing array for UI feedback
        this.syndicatingItems.push(item.id);
        
        // Get the config to determine the syndication delay
        // First try to fetch it from settings
        let delay = 60; // Default 60 minutes
        if (this.settings && this.settings.syndication_delay) {
          delay = this.settings.syndication_delay;
        }
        
        // Make API request to unignore and set back in queue
        const csrfToken = this.csrf || "";
        const response = await fetch('/api/posse/unignore', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF': csrfToken
          },
          body: JSON.stringify({
            id: item.id,
            delay: delay
          })
        });
        
        const data = await response.json();
        
        if (!response.ok) {
          throw new Error(data.message || 'Failed to unignore item');
        }
        
        // Show success message
        this.$store.dispatch('notification/success', 'Item added back to queue');
        
        // Refresh the queue to update UI
        this.fetchQueue();
      } catch (error) {
        console.error('Error unignoring item:', error);
        this.$store.dispatch('notification/error', 'Failed to unignore item: ' + error.message);
      } finally {
        // Remove from processing array
        const index = this.syndicatingItems.indexOf(item.id);
        if (index > -1) {
          this.syndicatingItems.splice(index, 1);
        }
      }
    }
  }
};
</script>