<?php
/**
 * Default POSSE template snippet
 * 
 * Available variables:
 * - $page: The Kirby page object
 * - $title: The page title
 * - $url: The page URL
 * - $tags: Array of hashtags
 */
?>
<?= $title ?>

<?= $url ?>

<?= implode(' ', $tags) ?> 