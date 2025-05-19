<template>
  <section class="k-section">
    <!-- Table header section -->
    <header class="k-section-header">
      <h2 class="k-label k-section-label" title="Queue">
        <span class="k-label-text">Queue</span>
      </h2>
    </header>

    <!-- Kirby styled table -->
    <div class="k-table">
      <table>
        <thead>
          <tr>
            <th data-column-id="page" data-mobile="true" class="k-table-column" style="width: 40%;">Page</th>
            <th data-column-id="service" class="k-table-column" style="width: 10%;">Service</th>
            <th data-column-id="syndicate_after" class="k-table-column" style="width: 20%;">Syndicate</th>
            <th data-column-id="status" data-mobile="true" class="k-table-column" style="width: 10%;">Status</th>
            <th data-column-id="actions" data-mobile="true" class="k-table-column" style="width: 20%;">Actions</th>
          </tr>
        </thead>
        <tbody class="k-draggable" v-if="items && items.length > 0">
          <tr v-for="item in items" :key="item.id">
            <!-- Page title with link and published date -->
            <td data-column-id="page" data-mobile="true" class="k-table-cell k-table-column" style="width: 40%; vertical-align: middle;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; align-items: flex-start;">
                <p :data-link="item.page_url" class="k-url-field-preview" style="padding-bottom: 0; margin-bottom: 0; width: 100%;">
                  <a :href="item.page_url" class="k-link" target="_blank">
                    <span>{{ item.page_title }}</span>
                  </a>
                </p>
                <p class="k-text-field-preview" style="color: var(--color-gray-500); font-size: 0.85em; margin-top: 0.1rem; margin-bottom: 0; padding-bottom: 0;">
                  Published: {{ formatDateTime(item.published_at || item.published_time) }} | 
                  <a :href="item.panel_url || '#'" class="k-link posse-panel-link" target="_blank">
                    Open in panel
                  </a>
                </p>
              </div>
            </td>
            
            <!-- Service -->
            <td data-column-id="service" class="k-table-cell k-table-column" style="width: 10%; vertical-align: middle;">
              <div style="padding: 0.5rem 0.75rem; display: flex; justify-content: flex-start;">
                <span v-if="item.service === 'mastodon'" title="Mastodon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M11.19 12.195c2.016-.24 3.77-1.475 3.99-2.603.348-1.778.32-4.339.32-4.339 0-3.47-2.286-4.488-2.286-4.488C12.062.238 10.083.017 8.027 0h-.05C5.92.017 3.942.238 2.79.765c0 0-2.285 1.017-2.285 4.488l-.002.662c-.004.64-.007 1.35.011 2.091.083 3.394.626 6.74 3.78 7.57 1.454.383 2.703.463 3.709.408 1.823-.1 2.847-.647 2.847-.647l-.06-1.317s-1.303.41-2.767.36c-1.45-.05-2.98-.156-3.215-1.928a3.614 3.614 0 0 1-.033-.496s1.424.346 3.228.428c1.103.05 2.137-.064 3.188-.189zm1.613-2.47H11.13v-4.08c0-.859-.364-1.295-1.091-1.295-.804 0-1.207.517-1.207 1.541v2.233H7.168V5.89c0-1.024-.403-1.541-1.207-1.541-.727 0-1.091.436-1.091 1.296v4.079H3.197V5.522c0-.859.22-1.541.66-2.046.456-.505 1.052-.764 1.793-.764.856 0 1.504.328 1.933.983L8 4.39l.417-.695c.429-.655 1.077-.983 1.934-.983.74 0 1.336.259 1.791.764.442.505.661 1.187.661 2.046v4.203z"/>
                  </svg>
                </span>
                <span v-else-if="item.service === 'bluesky'" title="Bluesky">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-bluesky" viewBox="0 0 16 16">
                    <path d="M3.468 1.948C5.303 3.325 7.276 6.118 8 7.616c.725-1.498 2.698-4.29 4.532-5.668C13.855.955 16 .186 16 2.632c0 .489-.28 4.105-.444 4.692-.572 2.04-2.653 2.561-4.504 2.246 3.236.551 4.06 2.375 2.281 4.2-3.376 3.464-4.852-.87-5.23-1.98-.07-.204-.103-.3-.103-.218 0-.081-.033.014-.102.218-.379 1.11-1.855 5.444-5.231 1.98-1.778-1.825-.955-3.65 2.28-4.2-1.85.315-3.932-.205-4.503-2.246C.28 6.737 0 3.12 0 2.632 0 .186 2.145.955 3.468 1.948"/>
                  </svg>
                </span>
                <span v-else :title="formatService(item.service)">
                  {{ formatService(item.service).charAt(0) }}
                </span>
              </div>
            </td>
            
            <!-- Syndicate after date -->
            <td data-column-id="syndicate_after" class="k-table-cell k-table-column" style="width: 20%; vertical-align: middle;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; align-items: flex-start;">
                <p class="k-text-field-preview posse-relative-time-large" style="padding-bottom: 0; margin-bottom: 0; width: 100%;">
                  {{ getRelativeTime(item.syndicate_after || item.earliest_syndication_time) }}
                </p>
                <p class="k-text-field-preview posse-timestamp-small" style="color: var(--color-gray-500); font-size: 0.85em; margin-top: 0.1rem; margin-bottom: 0; padding-bottom: 0;">
                  Syndicate after: {{ formatDateTime(item.syndicate_after || item.earliest_syndication_time) }}
                </p>
              </div>
            </td>
            
            <!-- Status badge -->
            <td data-column-id="status" data-mobile="true" class="k-table-cell k-table-column" style="width: 10%; vertical-align: middle;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; justify-content: center;">
                <button 
                  v-if="isSyndicated(item)" 
                  data-has-icon="true" 
                  data-has-text="true"
                  data-size="xs" 
                  data-theme="positive" 
                  data-variant="filled" 
                  type="button"
                  class="k-button k-table-update-status-cell-button"
                >
                  <span class="k-button-icon">
                    <svg aria-hidden="true" data-type="check" class="k-icon">
                      <use xlink:href="#icon-check"></use>
                    </svg>
                  </span>
                  <span class="k-button-text">Syndicated</span>
                </button>
                <button 
                  v-else-if="isIgnored(item)" 
                  data-has-icon="true" 
                  data-has-text="true"
                  data-size="xs" 
                  data-theme="disabled" 
                  data-variant="filled" 
                  type="button"
                  class="k-button k-table-update-status-cell-button"
                >
                  <span class="k-button-icon">
                    <svg aria-hidden="true" data-type="cancel" class="k-icon">
                      <use xlink:href="#icon-cancel"></use>
                    </svg>
                  </span>
                  <span class="k-button-text">Ignored</span>
                </button>
                <button 
                  v-else
                  data-has-icon="true" 
                  data-has-text="true"
                  data-size="xs" 
                  data-theme="notice" 
                  data-variant="filled" 
                  type="button"
                  class="k-button k-table-update-status-cell-button"
                >
                  <span class="k-button-icon">
                    <svg aria-hidden="true" data-type="upload" class="k-icon">
                      <use xlink:href="#icon-upload"></use>
                    </svg>
                  </span>
                  <span class="k-button-text">In Queue</span>
                </button>
              </div>
            </td>
            
            <!-- Action buttons -->
            <td data-column-id="actions" data-mobile="true" class="k-table-cell k-table-column" style="width: 20%; vertical-align: middle; text-align: left;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; justify-content: flex-start; align-items: flex-start;">
                <span v-if="isSyndicated(item)" class="k-text-field-preview">—</span>
                <div v-else-if="!isSyndicating(item)" style="display: flex; flex-direction: column; align-items: flex-start; gap: 5px; width: 100%;">
                  <k-button 
                    icon="upload" 
                    title="Syndicate now" 
                    text="Syndicate now"
                    @click="$emit('syndicate-now', item)" 
                    size="small"
                    style="width: 100%; justify-content: flex-start;"
                    class="posse-action-button"
                  />
                  <k-button 
                    icon="cancel" 
                    title="Ignore" 
                    text="Ignore"
                    @click="$emit('toggle-ignored', item)" 
                    size="small"
                    data-theme="info" 
                    data-variant="ghost"
                    style="width: 100%; justify-content: flex-start;"
                    class="posse-action-button"
                  />
                </div>
                <k-button v-else icon="loader" text="Syndicating..." size="small" title="Currently syndicating" disabled style="justify-content: flex-start;" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      
      <!-- Empty queue state -->
      <div v-if="!items || items.length === 0" class="k-collection-empty posse-empty-state">
        <div v-if="!error && items.length === 0 && !postsLoading && posts.length > 0" class="k-empty">
          <p class="posse-empty-description">
            We automatically add newly published pages here. 
            <a href="#" class="posse-action-link" @click.prevent="$emit('add-to-queue')">Add one manually</a> to get started.
          </p>
        </div>
        
        <!-- No Posts Error State -->
        <div v-else-if="!error && !postsLoading && posts.length === 0" class="k-empty">
          <p class="posse-empty-description">
            No content types configured. Please go to 
            <a href="#" class="posse-action-link" @click.prevent="$emit('go-to-settings')">settings</a> 
            and configure the content types you want to track.
          </p>
        </div>
        
        <!-- Error State -->
        <div v-else-if="error" class="k-empty">
          <p class="posse-empty-description">{{ error }}</p>
        </div>
        
        <!-- Loading state -->
        <div v-else class="k-empty">
          <p class="posse-empty-description">Loading syndication queue...</p>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
export default {
  props: {
    items: {
      type: Array,
      default: () => []
    },
    posts: {
      type: Array,
      default: () => []
    },
    loading: {
      type: Boolean,
      default: false
    },
    postsLoading: {
      type: Boolean,
      default: false
    },
    error: {
      type: [String, null],
      default: null
    },
    syndicatingItems: {
      type: Array,
      default: () => []
    }
  },
  methods: {
    formatService(service) {
      if (!service) return '';
      // Capitalize first letter
      return service.charAt(0).toUpperCase() + service.slice(1);
    },
    formatDateTime(dateString) {
      if (!dateString) return '';
      
      // Format: 'YYYY-MM-DD HH:MM:SS' from MySQL/SQLite
      // Replace space with 'T' and add 'Z' to specify UTC
      const utcDateString = dateString.replace(' ', 'T') + 'Z';
      
      // Parse as UTC, but will display in local timezone
      const date = new Date(utcDateString);
      
      // Check for invalid date
      if (isNaN(date.getTime())) {
        return dateString;
      }
      
      // Format as YYYY-MM-DD HH:MM in local time
      const pad = (num) => String(num).padStart(2, '0');
      const year = date.getFullYear();
      const month = pad(date.getMonth() + 1);
      const day = pad(date.getDate());
      const hours = pad(date.getHours());
      const minutes = pad(date.getMinutes());
      
      return `${year}-${month}-${day} ${hours}:${minutes}`;
    },
    getRelativeTime(dateString) {
      if (!dateString) return '';
      
      // Format: 'YYYY-MM-DD HH:MM:SS' from MySQL/SQLite
      // Explicitly treat these timestamps as UTC by appending 'Z'
      const utcDateString = dateString.replace(' ', 'T') + 'Z';
      
      // Create date objects directly, treating the input as UTC 
      const date = new Date(utcDateString);
      
      // Check for invalid date
      if (isNaN(date.getTime())) {
        return 'Unknown time';
      }
      
      // Get current time in UTC
      const now = new Date();
      
      // Calculate time difference in milliseconds
      const diffMs = date.getTime() - now.getTime();
      
      // If within 1 minute in the past, show "Now"
      if (diffMs < 0 && Math.abs(diffMs) < 60000) {
        return 'Now';
      }
      
      // If more than 1 minute in the past
      if (diffMs < -60000) {
        const pastMinutes = Math.floor(Math.abs(diffMs) / (1000 * 60));
        
        if (pastMinutes < 60) {
          return `${pastMinutes} minute${pastMinutes !== 1 ? 's' : ''} ago`;
        }
        
        const pastHours = Math.floor(pastMinutes / 60);
        if (pastHours < 24) {
          return `${pastHours} hour${pastHours !== 1 ? 's' : ''} ago`;
        }
        
        const pastDays = Math.floor(pastHours / 24);
        return `${pastDays} day${pastDays !== 1 ? 's' : ''} ago`;
      }
      
      // Future times
      // Convert to minutes
      const diffMinutes = Math.ceil(diffMs / (1000 * 60));
      
      if (diffMinutes < 60) {
        return `In ${diffMinutes} minute${diffMinutes !== 1 ? 's' : ''}`;
      }
      
      const diffHours = Math.floor(diffMinutes / 60);
      const remainingMinutes = diffMinutes % 60;
      
      if (diffHours < 24) {
        if (remainingMinutes === 0) {
          return `In ${diffHours} hour${diffHours !== 1 ? 's' : ''}`;
        }
        return `In ${diffHours}h ${remainingMinutes}m`;
      }
      
      const diffDays = Math.floor(diffHours / 24);
      return `In ${diffDays} day${diffDays !== 1 ? 's' : ''}`;
    },
    isSyndicated(item) {
      const hasSyndicatedAt = item.syndicated_at && item.syndicated_at !== "null" && item.syndicated_at !== "";
      const hasSyndicationTime = item.syndication_time && item.syndication_time !== "null" && item.syndication_time !== "";
      return hasSyndicatedAt || hasSyndicationTime;
    },
    isIgnored(item) {
      // Handle different possible values for ignored
      return item.ignored && item.ignored !== '0' && item.ignored !== 0;
    },
    isSyndicating(item) {
      // Check if this item is currently being syndicated
      return Array.isArray(this.syndicatingItems) && 
             this.syndicatingItems.some(id => id === item.id || id === Number(item.id) || String(id) === String(item.id));
    }
  }
};
</script>

<style>
.posse-action-button {
  transition: color 0.2s ease-in-out;
}

.posse-action-button:hover .k-button-text {
  color: var(--color-focus);
  text-decoration: underline;
}

.posse-action-button:hover .k-button-icon {
  color: var(--color-focus);
}

/* Empty state styling */
.posse-empty-state {
  padding: 2rem 0;
}

.posse-empty-state .k-empty {
  padding: 2rem;
}

.posse-empty-description {
  font-size: 1rem;
  color: var(--color-text);
  margin: 1rem 0;
  max-width: 400px;
  margin-left: auto;
  margin-right: auto;
  line-height: 1.5;
  text-align: center;
}

.posse-action-link {
  color: var(--color-focus);
  text-decoration: none;
  font-weight: 500;
  transition: all 0.2s;
}

.posse-action-link:hover {
  text-decoration: underline;
}

.posse-relative-time-large {
  color: var(--color-text);
  font-size: 1em;
  font-weight: 400;
  margin: 0;
  padding-bottom: 0;
  margin-bottom: 0;
  text-align: left;
  width: 100%;
}

.posse-timestamp-small {
  color: var(--color-gray-500);
  font-size: 0.85em;
  margin-top: 0.1rem;
  margin-bottom: 0;
  padding-bottom: 0;
  text-align: left;
}
</style>