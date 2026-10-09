<?php

declare(strict_types=1);

namespace {
    spl_autoload_register(function (string $class) {
        $prefix = 'OCA\\NdDownloader\\';
        $baseDir = __DIR__ . '/../lib/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

namespace OCP {
    if (!interface_exists(IConfig::class)) {
        interface IConfig {
            public function getAppValue(string $app, string $key, $default = null);
            public function setAppValue(string $app, string $key, $value);
            public function deleteAppValue(string $app, string $key);
            public function getSystemValue(string $key, $default = null);
        }
    }
}

namespace OCP\Security {
    if (!interface_exists(ICrypto::class)) {
        interface ICrypto {
            public function encrypt(string $plainText, string $passphrase = ''): string;
            public function decrypt(string $cipherText, string $passphrase = ''): string;
        }
    }
}

namespace OCP\Files {
    if (!interface_exists(IRootFolder::class)) {
        interface IRootFolder {
            public function getUserFolder(string $userId): Folder;
        }
    }

    if (!class_exists(Node::class)) {
        class Node {
            protected string $path = '/';
            protected string $internalPath = '';

            public function __construct(string $path = '/', string $internalPath = '') {
                $this->path = $path;
                $this->internalPath = $internalPath;
            }

            public function getId(): int { return 1; }
            public function getPath(): string { return $this->path; }
            public function getInternalPath(): string { return $this->internalPath; }
            public function getStorage() {
                return new class {
                    public function getScanner() {
                        return new class {
                            public function scan(string $path): void {}
                        };
                    }
                };
            }
        }
    }

    if (!class_exists(Folder::class)) {
        class Folder extends Node {
            /** @var array<string, Node> */
            protected array $children = [];

            public function nodeExists(string $path): bool {
                return isset($this->children[$path]);
            }

            public function get(string $path): Node {
                if (!isset($this->children[$path])) {
                    throw new \RuntimeException("Node not found: {$path}");
                }
                return $this->children[$path];
            }

            public function newFolder(string $path): Folder {
                $folder = new Folder(rtrim($this->path, '/') . '/' . $path, $this->internalPath . '/' . $path);
                $this->children[$path] = $folder;
                return $folder;
            }

            public function newFile(string $path): File {
                $file = new File(rtrim($this->path, '/') . '/' . $path, $this->internalPath . '/' . $path);
                $this->children[$path] = $file;
                return $file;
            }

            public function getChildren(): array {
                return $this->children;
            }
        }
    }

    if (!class_exists(File::class)) {
        class File extends Node {
            protected string $content = '';

            public function setContent($content): void {
                if (is_resource($content)) {
                    $this->content = (string)stream_get_contents($content);
                } else {
                    $this->content = (string)$content;
                }
            }

            public function getContent(): string {
                return $this->content;
            }
        }
    }
}

namespace OCP\Http\Client {
    if (!interface_exists(IClientService::class)) {
        interface IClientService {
            public function newClient();
        }
    }
}

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface {
            public function emergency($message, array $context = []): void;
            public function alert($message, array $context = []): void;
            public function critical($message, array $context = []): void;
            public function error($message, array $context = []): void;
            public function warning($message, array $context = []): void;
            public function notice($message, array $context = []): void;
            public function info($message, array $context = []): void;
            public function debug($message, array $context = []): void;
            public function log($level, $message, array $context = []): void;
        }
    }
}
