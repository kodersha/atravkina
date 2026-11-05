<?php
// GitHub Sync extension for Datenstrom Yellow.

class YellowGithubSync {
    const VERSION = "1.1.0";
    public $yellow;
    public $settings = array();

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $file = $yellow->system->get("coreExtensionDirectory")."yellow-github-sync.ini";
        $this->settings = $this->readSettings($file);
        if (!$this->isConfigured()) {
            $this->yellow->toolbox->log("error", "GitHub Sync: repository or token is missing. Check system/extensions/yellow-github-sync.ini");
        }
        if ($this->autoSync()) $this->syncChanges();
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

    // Yellow edit hooks provide an immediate second check after content/media changes.
    // The filesystem scan in onLoad also catches deletions and changes made outside the editor.
    public function onEditContentFile($page, $action, $email) {
        if ($this->autoSync()) $this->syncChanges("content");
    }

    public function onEditMediaFile($file, $action, $email) {
        if ($this->autoSync()) $this->syncChanges("media");
    }

    private function readSettings($file) {
        $settings = array();
        if (!is_file($file) || !is_readable($file)) return $settings;
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

        // Build the complete local file list before making remote changes. If a directory
        // cannot be read, abort rather than interpreting an incomplete scan as deletions.
        $files = array();
        try {
            foreach ($directories as $prefix=>$directory) {
                if (!is_dir($directory) || !is_readable($directory)) {
                    $this->yellow->toolbox->log("error", "GitHub Sync: directory not found or unreadable: $directory");
                    return 500;
                }
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $entry) {
                    if (!$entry->isFile() || $entry->isLink()) continue;
                    $relative = substr($entry->getPathname(), strlen(rtrim($directory, "/\\\\/"))+1);
                    $relative = str_replace(DIRECTORY_SEPARATOR, "/", $relative);
                    if (strpos($relative, ".git/")===0 || $relative==".git") continue;
                    $files[$prefix."/".$relative] = $entry->getPathname();
                }
            }
        } catch (Exception $exception) {
            $this->yellow->toolbox->log("error", "GitHub Sync: directory scan failed: ".$exception->getMessage());
            return 500;
        }

        $state = $this->loadState();
        $status = 200;
        foreach ($files as $path=>$fileName) {
            $data = file_get_contents($fileName);
            if ($data===false) {
                $this->yellow->toolbox->log("error", "GitHub Sync: cannot read $path");
                $status = 500;
                continue;
            }

            // Compare with GitHub's Git blob SHA, not only the local state cache.
            // This repairs files deleted or changed directly in the remote repository.
            $localBlobSha = sha1("blob ".strlen($data)."\0".$data);
            $remote = $this->request("GET", $this->fileEndpoint($path));
            if ($remote["status"]==200 && isset($remote["json"]["sha"]) &&
                hash_equals($remote["json"]["sha"], $localBlobSha)) {
                $state[$path] = hash("sha256", $data);
                continue;
            }
            if ($remote["status"]!=200 && $remote["status"]!=404) {
                $this->logFailure($path, $remote);
                $status = 500;
                continue;
            }

            if ($this->uploadFile($fileName, $path)==200) {
                $state[$path] = hash("sha256", $data);
            } else {
                $status = 500;
            }
        }

        // Only paths recorded as previously synchronized may be deleted. Never act on
        // malformed paths or outside the requested scope.
        foreach ($state as $path=>$fingerprint) {
            if (!is_string($path) ||
                !preg_match("#^(content|media)/[^\\\\\\\\]+(?:/[^\\\\\\\\]+)*$#", $path) ||
                (($scope=="content" || $scope=="media") && strpos($path, $scope."/")!==0)) continue;
            if (isset($files[$path])) continue;
            if ($this->deleteFile($path)==200) {
                unset($state[$path]);
            } else {
                $status = 500;
            }
        }

        if (!$this->saveState($state)) $status = 500;
        return $status;
    }

    private function uploadFile($fileName, $path) {
        $size = filesize($fileName);
        if ($size===false || $size>($this->maxFileSize()*1024*1024)) {
            $this->yellow->toolbox->log("error", "GitHub Sync: file size limit exceeded for $path");
            return 413;
        }
        $data = file_get_contents($fileName);
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
        $this->yellow->toolbox->log("info", "GitHub Sync: synchronized $path");
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
        $this->yellow->toolbox->log("info", "GitHub Sync: deleted $path");
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
        if (function_exists("curl_init")) {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            if ($body!==null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
        } else {
            $context = stream_context_create(array("http" => array(
                "method" => $method, "header" => implode("\r\n", $headers),
                "content" => $body===null ? "" : $body, "timeout" => 30,
                "ignore_errors" => true
            )));
            $response = @file_get_contents($url, false, $context);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $matches)) $status = (int)$matches[1];
            $error = $response===false ? "HTTP request failed" : "";
        }
        $json = is_string($response) ? json_decode($response, true) : null;
        return array("status"=>$status, "json"=>$json, "error"=>$error);
    }

    private function logFailure($path, $result) {
        $message = isset($result["json"]["message"]) ? $result["json"]["message"] : $result["error"];
        $this->yellow->toolbox->log("error", "GitHub Sync: failed for $path (HTTP ".$result["status"]."): ".$message);
    }

    private function loadState() {
        $file = $this->stateFile();
        if (!is_file($file)) return array();
        $state = json_decode(file_get_contents($file), true);
        return is_array($state) ? $state : array();
    }

    private function saveState($state) {
        $json = json_encode($state);
        return file_put_contents($this->stateFile(), $json, LOCK_EX)!==false;
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
        return preg_match("/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/", $this->repository()) && !is_string_empty($this->token());
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

    private function autoSync() {
        return !isset($this->settings["AutoSync"]) || (int)$this->settings["AutoSync"]==1;
    }
}
