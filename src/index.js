import './index.css';
import PosseView from './components/PosseView.vue';
import PosseSettings from './components/PosseSettings.vue';

// Register the plugin with Kirby 5 Panel
panel.plugin('notmyhostname/posse', {
  // Register our custom view components
  components: {
    'posse-view': PosseView,
    'posse-settings': PosseSettings
  }
});