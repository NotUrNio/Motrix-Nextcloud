<?php

declare(strict_types=1);

namespace OCA\Motrix\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects Motrix shortcut scripts and styles into Nextcloud Files app.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class FilesLoadAdditionalScriptsListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        Util::addScript('motrix', 'files-menu');
        Util::addStyle('motrix', 'files-menu');
    }
}
