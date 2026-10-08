<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects ND Downloader shortcut scripts and styles into Nextcloud Files app.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class FilesLoadAdditionalScriptsListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        Util::addScript('nddownloader', 'files-menu');
        Util::addStyle('nddownloader', 'files-menu');
    }
}
