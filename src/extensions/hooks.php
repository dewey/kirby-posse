<?php

/**
 * POSSE Hooks
 * 
 * Defines the hooks for the POSSE plugin to capture page events
 */

use Notmyhostname\Posse\Models\Posse;

return [
    'page.create:after' => function ($page) {
        try {
            if ($page->status() === 'listed') {
                $posse = new Posse();
                $posse->handlePage($page);
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin Error: ' . $e->getMessage());
        }
    },
    'page.update:after' => function ($newPage, $oldPage) {
        try {
            // Only react if the page was published (status changed to listed)
            if ($newPage->status() === 'listed' && $oldPage->status() !== 'listed') {
                $posse = new Posse();
                $posse->handlePage($newPage);
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin Error: ' . $e->getMessage());
        }
    },
    'page.changeStatus:after' => function ($newPage, $oldPage) {
        try {
            // Only react if the page was published (status changed to listed)
            if ($newPage->status() === 'listed' && $oldPage->status() !== 'listed') {
                $posse = new Posse();
                $posse->handlePage($newPage);
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin Error: ' . $e->getMessage());
        }
    }
];