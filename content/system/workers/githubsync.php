<?php
// GitHub Sync extension for Datenstrom Yellow.
// Synchronizes only content/ and media/ with the configured GitHub repository.

class YellowGithubSync {
    const VERSION = "1.2.0";

    public $yellow;
    public $settings = array();

    private $scheduledScopes = array();
    private $shutdownRegistered = false;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $file = $yellow->system->get("coreExtensionDirectory")."yellow-github-sync.ini";
        $this->settings = $this->readSettings($file);

        if (!$this->isConfigured()) {
            $this->yellow->toolbox->log("error", "GitHub Sync: repository or token is missing. Check system/extensions/yellow-github-sync.ini");
        }
    }

    public function onCommand($command, $text) {
        if ($command!="github-sync") return 0;
        if (!$this->isConfigured()) {
            echo "Yellow github-sync: Configure Repository and Token in yellow-github-sync.ini\n";
            return 400;
        }

        $args = preg_split("/\\s+/", trim($text));
        $mode = isset($args[0]) ? strtolower($args[0]) : "all";
        if ($mode=="" || $mode=="all") {
            $status = $this->syncChanges("all");
        } elseif ($mode=="content" || $mode=="media") {
            $status = $this->syncChanges($mode);
        } else {
            echo "Yellow github-sync: Use all, content or media\n";
            return 400;
        }
        echo "Yellow github-sync: ".($status==200 ? "Synchronization completed" : "Synchronization failed")."\n";
        return $status;
    }

    public function onCommandHelp() {
        return "github-sync [all|content|media]";
    }

    // Yellow Edit custom save notification: called only after the content file is written.
    public function onSaveContentFile($page) {
        $this->scheduleSync("content");
    }

    // Yellow Edit lifecycle event. Some actions are announced before the filesystem
    // operation; synchronization is deferred until shutdown to scan the final state.
    public function onEditContentFile($page, $action, $email) {
        $this->scheduleSync("content");
    }

    // Yellow Edit invokes this before completing uploads. Deferring the scan until
    // shutdown ensures that the final uploaded file, not its temporary source, is scanned.
    public function onEditMediaFile($file, $action, $email) {
        $this->scheduleSync("media");
    }

    private function scheduleSync($scope) {
        if (!$this->autoSync() || !$this->isConfigured()) return;
        $this->scheduledScopes[$scope] = true;
        $this->yellow->toolbox->log("info", "GitHub Sync: scheduled ".$scope." synchronization");
        if (!$this->shutdownRegistered) {
            register_shutdown_function(array($this, "runScheduledSync"));
            $this->shutdownRegistered = true;
        }
    }

    public function runScheduledSync() {
        if (empty($this->scheduledScopes)) return;
        $scope = count($this->scheduledScopes)>1 ? "all" : key($this->scheduledScopes);
        $this->scheduledScopes = array();
        $this->yellow->toolbox->log("info", "GitHub Sync: starting automatic ".$scope." synchronization");
        $status = $this->syncChanges($scope);
        if ($status!=200) {
            $this->yellow->toolbox->log("error", "GitHub Sync: automatic ".($scope)." synchronization failed");
        }
    }

    private function readSettings($file) {
        $settings = array();
        if (!is_file($file) || !is_readable($file)) {
            if (isset($this->yellow)) $this->yellow->toolbox->log("error", "GitHub Sync: configuration file is missing or unreadable: ".$file);
            return $settings;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line=="" || $line[0]=="#" || $line[0]==";") continue;
            $separator = strpos($line, ":");
            if ($separator===false) continue;
            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator+1));
            if ($key!="") $settings[$key] = $value;
        }
        return $settings;
    }

    private function syncChanges($scope = "all") {
        if (!$this->isConfigured()) return 400;

        $directories = array();
        if ($scope=="all" || $scope=="content") $directories["content"] = $this->contentDirectory();
        if ($scope=="all" || $scope=="media") $directories["media"] = $this->mediaDirectory();

        $files = array();
        foreach ($directories as $prefix=>$directory) {
            if (!is_dir($directory)) {
                $this->yellow->toolbox->log("error", "GitHub Sync: directory not found: ".$directory);
                return 500;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $entry) {
                    if (!$entry->isFile() || $entry->isLink()) continue;
                    $relative = substr($entry->getPathname(), strlen(rtrim($directory, "/\\\\/"))+1);
                    $relative = str_replace(DIRECTORY_SEPARATOR, "/", $relative);
                    if (strpos($relative, ".git/")===0 || $relative==".git") continue;
                    $files[$prefix."/".$relative] = $entry->getPathname();
                }
            } catch (Exception $exception) {
                $this->yellow->toolbox->log("error", "GitHub Sync: cannot scan ".$directory.": ".$exception->getMessage());
                return 500;
            }
        }

        $this->yellow->toolbox->log("info", "GitHub Sync: scanned ".count($files)." files in ".$scope);
        $state = $this->loadState();
        $status = 200;
        foreach ($files as $path=>$fileName) {
            $fingerprint = @hash_file("sha256", $fileName);
            if ($fingerprint===false) {
                $status = 500;
                $this->yellow->toolbox->log("error", "GitHub Sync: cannot read file ".$path);
                continue;
            }
            if (isset($state[$path]) && $state[$path]==$fingerprint) continue;
            if ($this->uploadFile($fileName, $path)==200) {
                $state[$path] = $fingerprint;
            } else {
                $status = 500;
            }
        }

        // Only delete paths inside the requested scope; never touch other directories.
        foreach ($state as $path=>$fingerprint) {
            // State files are local metadata. Never allow a stale/corrupt state entry
            // to delete anything outside the two explicitly synchronized folders.
            if (strpos($path, "content/")!==0 && strpos($path, "media/")!==0) continue;
            if (($scope=="content" && strpos($path, "content/")!==0) ||
                ($scope=="media" && strpos($path, "media/")!==0)) continue;
            if (isset($files[$path])) continue;
            if ($this->deleteFile($path)==200) {
                unset($state[$path]);
            } else {
                $status = 500;
            }
        }

        if (!$this->saveState($state)) {
            $this->yellow->toolbox->log("error", "GitHub Sync: cannot save synchronization state to ".$this->stateFile());
            $status = 500;
        }
        $this->yellow->toolbox->log($status==200 ? "info" : "error", "GitHub Sync: ".$scope." synchronization finished with status ".$status);
        return $status;
    }

    private function uploadFile($fileName, $path) {
        $size = @filesize($fileName);
        if ($size===false || $size>($this->maxFileSize()*1024*1024)) {
            $this->yellow->toolbox->log("error", "GitHub Sync: file size limit exceeded for ".$path);
            return 413;
        }
        $data = @file_get_contents($fileName);
        if ($data===false) return 500;

        $endpoint = $this->fileEndpoint($path);
        $existing = $this->request("GET", $endpoint);
        $sha = "";
        if ($existing["status"]==200 && isset($existing["json"]["sha"])) {
            $sha = $existing["json"]["sha"];
        } elseif ($existing["status"]!=404) {
            $this->logFailure($path, $existing);
            return $existing["status"];
        }

        $payload = array(
            "message" => "Yellow Sync: ".($sha!="" ? "Update " : "Add ").$path,
            "content" => base64_encode($data),
            "branch" => $this->branch()
        );
        if ($sha!="") $payload["sha"] = $sha;

        $result = $this->request("PUT", $endpoint, $payload);
        if ($result["status"]!=200 && $result["status"]!=201) {
            $this->logFailure($path, $result);
            return $result["status"];
        }
        $this->yellow->toolbox->log("info", "GitHub Sync: synchronized ".$path);
        return 200;
    }

    private function deleteFile($path) {
        $endpoint = $this->fileEndpoint($path);
        $existing = $this->request("GET", $endpoint);
        if ($existing["status"]==404) return 200;
        if ($existing["status"]!=200 || !isset($existing["json"]["sha"])) {
            $this->logFailure($path, $existing);
            return $existing["status"];
        }

        $result = $this->request("DELETE", $endpoint, array(
            "message" => "Yellow Sync: Delete ".$path,
            "sha" => $existing["json"]["sha"],
            "branch" => $this->branch()
        ));
        if ($result["status"]!=200) {
            $this->logFailure($path, $result);
            return $result["status"];
        }
        $this->yellow->toolbox->log("info", "GitHub Sync: deleted ".$path);
        return 200;
    }

    private function fileEndpoint($path) {
        $encoded = implode("/", array_map("rawurlencode", explode("/", $path)));
        return "https://api.github.com/repos/".$this->repository()."/contents/".$encoded;
    }

    private function request($method, $url, $payload = null) {
        $headers = array(
            "Accept: application/vnd.github+json",
            "Authorization: Bearer ".$this->token(),
            "X-GitHub-Api-Version: 2022-11-28",
            "User-Agent: Datenstrom-Yellow-GitHub-Sync",
            "Content-Type: application/json"
        );
        $body = $payload===null ? null : json_encode($payload);
        if ($body!==null && $body===false) {
            return array("status"=>0, "json"=>null, "error"=>"Cannot encode request payload");
        }

        if (function_exists("curl_init")) {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            if ($body!==null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 45);
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
        } else {
            $options = array(
                "method" => $method,
                "header" => implode("\r\n", $headers),
                "timeout" => 45,
                "ignore_errors" => true
            );
            if ($body!==null) $options["content"] = $body;
            $context = stream_context_create(array("http" => $options));
            $response = @file_get_contents($url, false, $context);
            $status = 0;
            if (isset($http_response_header[0]) &&
                preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $matches)) {
                $status = (int)$matches[1];
            }
            $error = $response===false ? "HTTP request failed" : "";
        }

        $json = is_string($response) ? json_decode($response, true) : null;
        return array("status"=>$status, "json"=>$json, "error"=>$error);
    }

    private function logFailure($path, $result) {
        $message = isset($result["json"]["message"]) ? $result["json"]["message"] : $result["error"];
        $this->yellow->toolbox->log("error", "GitHub Sync: failed for ".$path." (HTTP ".$result["status"]."): ".$message);
    }

    private function loadState() {
        $file = $this->stateFile();
        if (!is_file($file)) return array();
        $state = json_decode(@file_get_contents($file), true);
        return is_array($state) ? $state : array();
    }

    private function saveState($state) {
        $json = json_encode($state);
        if ($json===false) return false;
        return @file_put_contents($this->stateFile(), $json, LOCK_EX)!==false;
    }

    private function stateFile() {
        return rtrim($this->yellow->system->get("coreExtensionDirectory"), "/\\\\/").DIRECTORY_SEPARATOR.".yellow-github-sync-state.json";
    }

    private function contentDirectory() {
        return rtrim($this->yellow->system->get("coreServerInstallDirectory"), "/\\\\/").DIRECTORY_SEPARATOR."content".DIRECTORY_SEPARATOR;
    }

    private function mediaDirectory() {
        return rtrim($this->yellow->system->get("coreServerInstallDirectory"), "/\\\\/").DIRECTORY_SEPARATOR."media".DIRECTORY_SEPARATOR;
    }

    private function isConfigured() {
        return preg_match("/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/", $this->repository()) &&
            !is_string_empty($this->token());
    }

    private function repository() {
        return isset($this->settings["Repository"]) ? trim($this->settings["Repository"]) : "";
    }

    private function branch() {
        return isset($this->settings["Branch"]) && trim($this->settings["Branch"])!="" ? trim($this->settings["Branch"]) : "main";
    }

    private function token() {
        return isset($this->settings["Token"]) ? trim($this->settings["Token"]) : "";
    }

    private function maxFileSize() {
        return isset($this->settings["MaxFileSizeMB"]) ? max(1, (int)$this->settings["MaxFileSizeMB"]) : 20;
    }

    // Never contact GitHub during a web request. Synchronization is intended
    // to run from the CLI command (for example, through a nightly server cron).
    // This prevents slow GitHub API calls from blocking post saves and uploads.
    private function autoSync() {
        return false;
    }
}
