<?php
// GitHub Sync extension for Datenstrom Yellow.

class YellowGithubSync {
    const VERSION = "1.0.0";
    public $yellow;
    public $settings = array();

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $file = $yellow->system->get("coreExtensionDirectory")."yellow-github-sync.ini";
        $this->settings = is_file($file) ? parse_ini_file($file, false, INI_SCANNER_RAW) : array();
        if (!is_array($this->settings)) $this->settings = array();
    }

    public function onCommand($command, $text) {
        if ($command!="github-sync") return 0;
        if (!preg_match("/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/", $this->repository()) || is_string_empty($this->token())) {
            echo "Yellow github-sync: Configure Repository and Token in yellow-github-sync.ini\n";
            return 400;
        }
        $args = preg_split("/\\s+/", trim($text));
        $mode = isset($args[0]) ? strtolower($args[0]) : "all";
        if ($mode=="" || $mode=="all") {
            $status = $this->syncDirectory($this->contentDirectory(), "content", array(".md"));
            if ($status==200) $status = $this->syncDirectory($this->mediaDirectory(), "media", $this->mediaExtensions());
        } elseif ($mode=="content") {
            $status = $this->syncDirectory($this->contentDirectory(), "content", array(".md"));
        } elseif ($mode=="media" || $mode=="images") {
            $status = $this->syncDirectory($this->mediaDirectory(), "media", $this->mediaExtensions());
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

    public function onEditContentFile($page, $action, $email) {
        if (!$this->autoSync() || !in_array($action, array("create", "edit", "restore")) || $page->isError()) return;
        $file = $this->yellow->lookup->findFileFromContentLocation($page->getLocation());
        if (is_string($file) && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION))=="md") {
            $this->syncFile($file, "content");
        }
    }

    public function onEditMediaFile($file, $action, $email) {
        if (!$this->autoSync() || !in_array($action, array("create", "edit", "restore")) || $file->isError()) return;
        $path = $this->yellow->lookup->findFileFromMediaLocation($file->getLocation());
        if (is_string($path) && is_file($path) && in_array(".".strtolower(pathinfo($path, PATHINFO_EXTENSION)), $this->mediaExtensions())) {
            $this->syncFile($path, "media");
        }
    }

    private function syncDirectory($directory, $prefix, $extensions) {
        if (!is_dir($directory)) {
            echo "Yellow github-sync: Directory not found: $directory\n";
            return 500;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        $status = 200;
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) continue;
            $extension = ".".strtolower($entry->getExtension());
            if (!in_array($extension, $extensions)) continue;
            if ($this->syncFile($entry->getPathname(), $prefix)!=200) $status = 500;
        }
        return $status;
    }

    private function syncFile($fileName, $prefix) {
        $root = $prefix=="content" ? $this->contentDirectory() : $this->mediaDirectory();
        $relative = str_replace(DIRECTORY_SEPARATOR, "/", substr($fileName, strlen(rtrim($root, DIRECTORY_SEPARATOR))+1));
        $path = $prefix."/".$relative;
        $size = filesize($fileName);
        if ($size===false || $size>($this->maxFileSize()*1024*1024)) {
            $this->yellow->toolbox->log("error", "GitHub Sync: file size limit exceeded for $path");
            return 413;
        }
        $data = file_get_contents($fileName);
        if ($data===false) return 500;
        $endpoint = "https://api.github.com/repos/".$this->repository()."/contents/".str_replace("%2F", "/", rawurlencode($path));
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

    private function contentDirectory() {
        return rtrim($this->yellow->system->get("coreServerInstallDirectory"), "/\\").DIRECTORY_SEPARATOR."content".DIRECTORY_SEPARATOR;
    }

    private function mediaDirectory() {
        return rtrim($this->yellow->system->get("coreServerInstallDirectory"), "/\\").DIRECTORY_SEPARATOR."media".DIRECTORY_SEPARATOR;
    }

    private function mediaExtensions() {
        $configured = isset($this->settings["Extensions"]) ? $this->settings["Extensions"] : ".gif, .jpeg, .jpg, .png, .svg, .webp";
        return array_map("trim", array_map("strtolower", explode(",", $configured)));
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
        return isset($this->settings["AutoSync"]) && (int)$this->settings["AutoSync"]==1;
    }
}
