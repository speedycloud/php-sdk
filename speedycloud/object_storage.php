<?php
require dirname(dirname(__FILE__))."/Requests/library/Requests.php";
Requests::register_autoloader();

class Create_Header{
    var $access_key;
    var $secret_key;
    function __construct($access_key, $secret_key) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
    }
    function create_sign_str($http_method, $url, $content_md5, $content_type, $params,$canonicalized_amz_headers) {
        date_default_timezone_set("UTC");
        $http_header_date = sprintf("%s%s",date("D, d M Y H:i:s "),"GMT");
        $sign_param_list = array($http_method, $content_md5, $content_type,$http_header_date);
        if($canonicalized_amz_headers) {
            array_push($sign_param_list, $canonicalized_amz_headers);
        }
        array_push($sign_param_list, $url);
        return join("\n",$sign_param_list);
    }
    function create_sign($method, $path, $params) {
        $canonicalized_amz_headers = "";
        $content_md5 = "";
        $content_type = "";
        $param = [];
        if(array_key_exists("x-amz-acl", $params)) {
            $canonicalized_amz_headers = sprintf("x-amz-acl:%s",$params["x-amz-acl"]);
        }
        if(array_key_exists("content_type", $params)) {
            $content_type = $params["content_type"];
        }
        if(array_key_exists("content_md5", $params)) {
            $content_md5 = $params["content_md5"];
        }
        if(array_key_exists("params", $params)) {
            $param = $params["params"];
        }
        $sign_str = $this->create_sign_str($http_method=$method,$url=$path,$content_md5=$content_md5,$content_type=$content_type,$params=$param,$canonicalized_amz_headers=$canonicalized_amz_headers);

        return base64_encode(hash_hmac("sha1", $sign_str, $this->secret_key, true));
    }
    function generate_headers($method, $path, $params) {
        date_default_timezone_set("UTC");
        $request_date = date("D, d M Y H:i:s ")."GMT";
        $sign = $this->create_sign($method, $path, $params);
        $authorization = "AWS"." ".$this->access_key.":".trim($sign);
        $header_data = array("Date"=>$request_date, "Authorization"=>$authorization);
        if(array_key_exists("x-amz-acl", $params)) {
            $header_data["x-amz-acl"] = $params["x-amz-acl"];
        }
        $header_data["Content-Type"] = "";
        if(array_key_exists("content_type", $params)) {
            $header_data["Content-Type"] = $params["content_type"];
        }
        if(array_key_exists("content_length", $params)) {
            $header_data["Content-Length"] = $params["content_length"];
        }
        return $header_data;
    }
}

class Send_request extends Create_Header {
    function __construct($access_key, $secret_key) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        parent::__construct($access_key, $secret_key);
    }

    function request($method, $path, $data, $params, $host) {
        if(substr($host, 0, 7)==="http://") {
            $url = sprintf("%s%s", $host, $path);
        } else {
            $url = sprintf("http://%s%s", $host, $path);
        }
        $header = $this->generate_headers($method, $path, $params);
        if($method=="GET") {
            $request = Requests::get($url,$header);
        }
        if($method=="PUT") {
            $request = Requests::put($url, $header, $data=$data);
        }
        if($method=="DELETE") {
            $request = Requests::delete($url, $header);
        }
        if($method=="POST") {
            $request = Requests::post($url, $header, $data=$data);
        }
        return $request->body;
    }
    function upload_big_data_put($method, $path, $data, $params, $host) {
        if(substr($host, 0, 7)==="http://") {
            $url = sprintf("%s%s", $host, $path);
        } else {
            $url = sprintf("http://%s%s", $host, $path);
        }
        $header = $this->generate_headers($method, $path, $params);
        $request = Requests::put($url, $header, $data=$data);
        return $request->headers['etag'];
    }
}

class Object_Storage {
    var $instance;
    function __construct($access_key,$secret_key){
        $this->instance = new Send_request($access_key,$secret_key);
    }
    function get_path($path) {
        $base_path = "/";
        return sprintf("%s%s", $base_path, $path);
    }

    function object_list($host, $bucket) {
        /*
        查询桶内对象列表
            参数:
                bucket: 桶名
            注意： bucket参数为''时，可查看所有桶
        */
        $real_path = $this->get_path($bucket);
        $result = $this->instance->request("GET", $real_path, "none", array(), $host);
        return $result;
    }

    function delete_bucket($host, $bucket) {
        /*
        注意： 在桶内没有对象的时候才能删除桶
            删除存储桶
            参数:
                bucket: 桶名
        */
        $real_path = $this->get_path($bucket);
        $result = $this->instance->request("DELETE", $real_path, null, array(), $host);
        return $result;
    }

    function create_bucket($host, $bucket) {
        /*
        创建存储桶
            参数:
                bucket: 桶名
        */
        $real_path = $this->get_path($bucket);
        $result = $this->instance->request("PUT", $real_path, null, array(), $host);
        return $result;
    }

    function query_bucket_acl($host, $bucket) {
        /*
        查询桶的权限
            参数:
                bucket: 桶名
        */
        $real_path = $this->get_path(sprintf("%s?acl",$bucket));
        $result = $this->instance->request("GET", $real_path, null, array(), $host);
        return $result;
    }

    function query_object_acl($host, $bucket, $key) {
        /*
        查询桶内对象的权限
            参数:
                bucket: 桶名
                key: 对象名
        */
        $real_path = $this->get_path(sprintf("%s/%s?acl", $bucket, $key));
        $result = $this->instance->request("GET", $real_path, null, array(), $host);
        return $result;
    }

    function delete_object_data($host, $bucket, $key) {
        /*
        删除桶内非版本管理对象
            注意： 删除成功不是返回200
            参数:
                bucket: 桶名
                key: 对象名
        */
        $real_path = $this->get_path(sprintf("%s/%s",$bucket, $key));
        $result = $this->instance->request("DELETE", $real_path, null, array(), $host);
        return $result;
    }

    function delete_versioning_object($host, $bucket, $key, $versionId) {
        /*
        删除桶内版本管理对象
        参数:
            bucket: 桶名
            key: 对象名
            versionId: 对象名
        */
        $real_path = $this->get_path(sprintf("%s/%s?versionId=%s", $bucket, $key, $versionId));
        $result = $this->instance->request("DELETE", $real_path, null, array(), $host);
        return $result;
    }

    function configure_versioning($host, $bucket, $status) {
        /*
        设置版本控制
        参数:
            bucket: 桶名
            status: 状态("Enabled"或者"Suspended")
        */
        $real_path = $this->get_path(sprintf("%s?versioning", $bucket));
        $VersioningBody = sprintf('<?xml version="1.0" encoding="UTF-8"?>
            <VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
              <Status>%s</Status>
            </VersioningConfiguration>',$status);
        $result = $this->instance->request("PUT", $real_path, $VersioningBody,  array(), $host);
        return $result;
    }

    function get_bucket_versioning($host, $bucket) {
        /*
        查看当前桶的版本控制信息，返回桶的状态（"Enabled"或者"Suspended"或者""）
        */
        $real_path = $this->get_path(sprintf("%s?versioning", $bucket));
        $result = $this->instance->request("GET", $real_path, null, array(), $host);
        return $result;
    }

    function get_object_versions($host, $bucket) {
        /*
        获取当前桶内的所有对象的所有版本信息
        */
        $real_path = $this->get_path(sprintf("%s?versions", $bucket));
        $result = $this->instance->request("GET", $real_path, null, array(), $host);
        return $result;
    }

    function download_object_data($host, $bucket, $key) {
        /*
        下载桶内对象的数据
            参数:
                bucket: 桶名
                key: 对象名
        */
        $real_path = $this->get_path(sprintf("%s/%s", $bucket, $key));
        $result = $this->instance->request("GET", $real_path, null, array(), $host);
        return $result;
    }

    function update_bucket_acl($host, $bucket, $header_params=array()) {
        /*
        修改桶的权限
            参数:
                bucket: 桶名
                header_params: 请求头参数， 是一个字典
                    array("x-amz-acl"=>"public-read")
                        test: 允许值
                            private：自己拥有全部权限，其他人没有任何权限
                            public-read：自己拥有全部权限，其他人拥有读权限
                            public-read-write：自己拥有全部权限，其他人拥有读写权限
                            authenticated-read：自己拥有全部权限，被授权的用户拥有读权限
        */
        $real_path = $this->get_path(sprintf("%s?acl", $bucket));
        $result = $this->instance->request("PUT", $real_path, null, $header_params, $host);
        return $result;
    }

    function update_object_acl($host, $bucket, $key, $header_params=array()) {
        /*
        修改桶内对象的权限
            参数:
                bucket: 桶名
                key: 对象名
                header_params: 请求头参数， 是一个字典
                    array("x-amz-acl"=>"public-read")
                        test: 允许值
                            private：自己拥有全部权限，其他人没有任何权限
                            public-read：自己拥有全部权限，其他人拥有读权限
                            public-read-write：自己拥有全部权限，其他人拥有读写权限
                            authenticated-read：自己拥有全部权限，被授权的用户拥有读权限
        */
        $real_path = $this->get_path(sprintf("%s/%s?acl", $bucket, $key));
        $result = $this->instance->request("PUT", $real_path, null, $header_params, $host);
        return $result;
    }

    function update_versioning_object_acl($host, $bucket, $key, $versionId, $header_params=array()) {
        /*
        修改桶内版本管理对象的权限
            参数:
                bucket: 桶名
                key: 对象名
                versionId: 对象版本号
                header_params: 请求头参数， 是一个字典
                    array("x-amz-acl"=>"public-read")
                        test: 允许值
                            private：自己拥有全部权限，其他人没有任何权限
                            public-read：自己拥有全部权限，其他人拥有读权限
                            public-read-write：自己拥有全部权限，其他人拥有读写权限
                            authenticated-read：自己拥有全部权限，被授权的用户拥有读权限
        */
        $real_path = $this->get_path(sprintf("%s/%s?acl&versionId=%s", $bucket, $key, $versionId));
        $result = $this->instance->request("PUT", $real_path, null, $header_params, $host);
        return $result;
    }

    function upload_big_data_one($host, $bucket, $key, $header_params) {
        $real_path = $this->get_path(sprintf("%s/%s?uploads", $bucket, $key));
        $xml = $this->instance->request("POST", $real_path, null, $header_params, $host);
        $upload_id = simplexml_load_string($xml)->UploadId;
        return $upload_id;
    }

    function upload_big_data_two($host, $bucket, $key, $update_data, $part_number, $upload_id, $header_params) {
        $update_content = $update_data;
        $real_path = $this->get_path(sprintf("%s/%s?partNumber=%s&uploadId=%s", $bucket, $key, $part_number, $upload_id));
        $header_data = $this->instance->upload_big_data_put("PUT", $real_path, $update_content, $header_params, $host);
        return $header_data;
    }

    function upload_big_data($host, $bucket, $key, $update_data, $header_params) {
        $uid = $this->upload_big_data_one($host, $bucket, $key, $header_params);
        $real_path = $this->get_path(sprintf("%s/%s?uploadId=%s", $bucket, $key, $uid));
        $size = filesize($update_data);
        $file = fopen("$update_data", "r");
        $rock = 1024*1024*20;
        if($size>1024*1024*1024) {
            echo "file is bigger than 1G";
            return "";
        }
        $i = $size / $rock;
        if($i != 0) {
            $i.=1;
        }
        $content = "";
        for ($x=0;$x<$i;$x++) {
            $etag = $this->upload_big_data_two($host, $bucket, $key, fread($file, $rock),$x+1,$uid, $header_params);
            $content.=sprintf("<Part><PartNumber>%s</PartNumber><ETag>%s</ETag></Part>", $x+1, $etag);
        }
        $content =  "<CompleteMultipartUpload>".$content."</CompleteMultipartUpload>";
        $result = $this->instance->request("POST", $real_path, $content, $header_params, $host);
        fclose($file);
        return $result;
    }

    function storing_object_data($host, $bucket, $key, $update_data, $update_type, $header_params=array()) {
        /*
        创建存储桶内对象
            参数:
                bucket: 桶名
                key: 对象名
                update_data: 对象的内容（文件的路径/字符串）
                update_type: 对象内容类型 允许值 'file','string'
        */
        $real_path = $this->get_path(sprintf("%s/%s", $bucket, $key));
        if($update_type=="string" || $update_type=="data") {
            $update_content = $update_data;
            $result = $this->instance->request("PUT", $real_path, $update_content, $header_params, $host);
        }
        if($update_type=="file") {
            $result = $this->upload_big_data($host, $bucket, $key,$update_data, $header_params);
        }
        return $result;
    }
}
?>



