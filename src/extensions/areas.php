<?php

/**
 * POSSE Panel Areas
 * 
 * Defines the custom panel area for the POSSE plugin
 */

return [
    'posse' => function () {
        return [
            'label' => 'POSSE',
            'icon' => 'share',
            'link' => 'posse',
            'menu' => [
                [
                    'text' => 'Queue',
                    'icon' => 'list',
                    'link' => 'posse'
                ],
                [
                    'text' => 'Settings',
                    'icon' => 'settings',
                    'link' => 'posse/settings'
                ]
            ],
            'views' => [
                [
                    'pattern' => 'posse',
                    'action'  => function () {
                        // Pass the CSRF token to the view
                        return [
                            'component' => 'posse-view',
                            'title' => 'POSSE',
                            'props' => [
                                'csrf' => csrf()
                            ]
                        ];
                    }
                ],
                [
                    'pattern' => 'posse/settings',
                    'action'  => function () {
                        // Pass the CSRF token to the view
                        return [
                            'component' => 'posse-settings',
                            'title' => 'POSSE Settings',
                            'props' => [
                                'csrf' => csrf()
                            ]
                        ];
                    }
                ]
            ]
        ];
    }
];