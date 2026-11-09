<?php
class YellowLikes {
 const VERSION="1.1.0"; public $yellow; private $dataDirectory; private $dataFile; private $legacyFile; private $endpoint="/yellow-likes/";
 public function onLoad($yellow) {
  $this->yellow=$yellow; $this->dataDirectory=$yellow->system->get("coreExtensionDirectory")."yellow-likes/";
  $this->dataFile=$this->dataDirectory."votes.json"; $this->legacyFile=$this->dataDirectory."votes.php";
  if(!is_dir($this->dataDirectory)) @mkdir($this->dataDirectory,0755,true);
 }
 public function onRequest($scheme,$address,$base,$location,$fileName) {
  $asset=trim($location,"/");
  if($asset==="yellow-likes/likes.css")return $this->sendAsset("likes.css","text/css; charset=utf-8");
  if($asset==="yellow-likes/likes.js")return $this->sendAsset("likes.js","application/javascript; charset=utf-8");
  if(rtrim($location,"/")."/"!=$this->endpoint)return 0; $this->sendJsonHeaders();
  if(!isset($_SERVER["REQUEST_METHOD"])||$_SERVER["REQUEST_METHOD"]!="POST")return $this->sendError("method_not_allowed",405);
  $type=isset($_SERVER["CONTENT_TYPE"])?strtolower(trim(explode(";",$_SERVER["CONTENT_TYPE"])[0])):"";
  if($type!="application/json")return $this->sendError("unsupported_media_type",415);
  if(isset($_SERVER["HTTP_ORIGIN"])) {
   $origin=parse_url($_SERVER["HTTP_ORIGIN"],PHP_URL_HOST); $host=isset($_SERVER["HTTP_HOST"])?strtolower(preg_replace("/:\\d+$/","",$_SERVER["HTTP_HOST"])):"";
   if(!$origin||strtolower($origin)!=$host)return $this->sendError("origin_not_allowed",403);
  }
  $input=json_decode(file_get_contents("php://input"),true);
  if(!is_array($input)||!isset($input["action"],$input["page"]))return $this->sendError("invalid_request",400);
  $page=$this->normalisePage($input["page"]); if(!$page||!in_array($input["action"],array("status","like"),true))return $this->sendError("invalid_request",400);
  $visitor=$this->getVisitorId(); if($visitor===false)return $this->sendError("storage_unavailable",503);
  $result=$this->updateVotes($page,$visitor,$input["action"]); if($result===false)return $this->sendError("storage_unavailable",503);
  $this->sendJson($result,200);return 200;
 }
 public function onParsePageOutput($page,$output) {
  $path=isset($_SERVER["REQUEST_URI"])?parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH):"";
  if($this->isEditPath($path)||$page->get("layout")!="blog"||!is_string($output)||strpos($output,'class="yellow-likes"')!==false)return null;
  $widget='<div class="yellow-likes" data-yellow-likes><button class="yellow-likes__button" type="button" aria-label="Поставить лайк" aria-pressed="false"><i class="las la-heart-broken"></i><span class="yellow-likes__count">0</span></button></div>';
  if(strpos($output,"<!--yellow-likes-slot-->")!==false)$output=str_replace("<!--yellow-likes-slot-->",$widget,$output);
  elseif(stripos($output,"</article>")!==false)$output=preg_replace("/<\\/article>/i",$widget."</article>",$output,1);else $output.=$widget;
  $assets='<link rel="stylesheet" href="/yellow-likes/likes.css"><script src="/yellow-likes/likes.js" defer></script>';
  if(stripos($output,"</head>")!==false)$output=preg_replace("/<\\/head>/i",$assets."</head>",$output,1);else $output=$assets.$output;
  return $output;
 }
 private function sendAsset($name,$type){
  $file=__DIR__."/yellow-likes/".$name;
  if(!is_file($file)||!is_readable($file)){http_response_code(404);return 404;}
  header("Content-Type: ".$type);header("Cache-Control: public, max-age=3600");header("X-Content-Type-Options: nosniff");
  readfile($file);return 200;
 }
 private function isEditPath($path){return is_string($path)&&preg_match("#^/e(?:/|$)#",$path)===1;}
 private function normalisePage($page){
  if(!is_string($page)||strlen($page)>500||strpos($page,"\\\\")!==false)return false;
  $path=parse_url($page,PHP_URL_PATH);
  if(!is_string($path)||$path===""||$path[0]!="/"||strpos($path,"/../")!==false||strpos($path,"\\0")!==false||$this->isEditPath($path))return false;
  return rtrim($path,"/")."/";
 }
 private function getVisitorId(){
  $name="yellow_likes_visitor";if(isset($_COOKIE[$name])&&preg_match("/^[a-f0-9]{64}$/",$_COOKIE[$name]))return hash("sha256",$_COOKIE[$name]);
  try{$token=bin2hex(random_bytes(32));}catch(Exception $e){return false;}
  $secure=(!empty($_SERVER["HTTPS"])&&strtolower($_SERVER["HTTPS"])!="off")||(isset($_SERVER["HTTP_X_FORWARDED_PROTO"])&&strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"])=="https");
  setcookie($name,$token,array("expires"=>time()+31536000,"path"=>"/","secure"=>$secure,"httponly"=>true,"samesite"=>"Lax"));$_COOKIE[$name]=$token;return hash("sha256",$token);
 }
 private function readLegacy(){
  $raw=@file_get_contents($this->legacyFile);$prefix="<?php http_response_code(404); exit; ?>\\n";
  if(is_string($raw)&&strpos($raw,$prefix)===0)$raw=substr($raw,strlen($prefix));
  $data=is_string($raw)?json_decode($raw,true):array();return is_array($data)?$data:array();
 }
 private function updateVotes($page,$visitor,$action){
  $h=@fopen($this->dataFile,"c+");if(!$h||!flock($h,LOCK_EX)){if($h)fclose($h);return false;}
  rewind($h);$raw=stream_get_contents($h);$data=($raw===""||$raw===false)?$this->readLegacy():json_decode($raw,true);if(!is_array($data))$data=array();
  if(!isset($data[$page])||!is_array($data[$page]))$data[$page]=array();$liked=in_array($visitor,$data[$page],true);
  if($action=="like"&&!$liked){$data[$page][]=$visitor;$liked=true;}$count=count($data[$page]);$json=json_encode($data,JSON_UNESCAPED_SLASHES);
  rewind($h);if(!is_string($json)||!ftruncate($h,0)){flock($h,LOCK_UN);fclose($h);return false;}
  $pos=0;$len=strlen($json);while($pos<$len){$n=fwrite($h,substr($json,$pos));if($n===false||$n===0){flock($h,LOCK_UN);fclose($h);return false;}$pos+=$n;}
  if(!fflush($h)){flock($h,LOCK_UN);fclose($h);return false;}flock($h,LOCK_UN);fclose($h);return array("count"=>$count,"liked"=>$liked);
 }
 private function sendError($error,$status){$this->sendJson(array("error"=>$error),$status);return $status;}
 private function sendJsonHeaders(){header("Content-Type: application/json; charset=utf-8");header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");header("X-Content-Type-Options: nosniff");header("Vary: Cookie");}
 private function sendJson($data,$status){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
