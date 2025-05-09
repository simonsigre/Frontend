<?php
require dirname(__FILE__) . '/../vendor/autoload.php';

/* ****************************************** */
/* Norman SampleShare Server Framework        */
/* Version 1.30                               */
/* Created by Trygve Brox - Norman ASA - 2010 */
/* ****************************************** */
/* Modified by Silas Cutler for Malshare.com  */
/*                                            */
/* ****************************************** */

error_reporting(E_ALL & ~E_NOTICE);

/* GLOBAL CONFIG VARS */

// Paths
define("SAMPLES_ROOT", getenv('MALSHARE_SAMPLES_ROOT'));
define("UPLOAD_SAMPLES_ROOT", getenv('MALSHARE_UPLOAD_SAMPLES_ROOT'));

// Tables
define("SAMPLES_TABLE", "tbl_samples");
define("SAMPLE_SOURCES_TABLE", "tbl_sample_sources");
define("USERS_TABLE", "tbl_users");
define("UPLOADS_TABLE", "tbl_uploads");
define("SEARCHES_TABLE", "tbl_searches");
define("PUBSEARCHES_TABLE", "tbl_public_searches");
define("URLDLTASKS_TABLE", "tbl_url_download_tasks");
define("SAMPLE_PARTNER_TABLE", "tbl_sample_partners");

// External API Connections
define("VT_CONTEXT_KEY", getenv("VT_CONTEXT_KEY"));
define("VT_CONTEXT_URL", getenv("VT_CONTEXT_URL"));

// DB Connection
define("DB_HOST", getenv('MALSHARE_DB_HOST'));
define("DB_USER", getenv('MALSHARE_DB_USER'));
define("DB_PASS", getenv('MALSHARE_DB_PASS'));
define("DB_DATABASE", getenv('MALSHARE_DB_DATABASE'));
define("DB_CA_PATH", getenv('MALSHARE_DB_CERT'));
define("DB_PORT", getenv('MALSHARE_DB_PORT'));

// Supported Hashing
define("HASH_SUPPORTED_MD5", "true");
define("HASH_SUPPORTED_SHA1", "true");
define("HASH_SUPPORTED_SHA256", "true");

// S3 config
define("WASABI_ENDPOINT", getenv('WASABI_ENDPOINT'));
define("WASABI_REGION", getenv('WASABI_REGION'));
define("WASABI_KEY", getenv('WASABI_KEY'));
define("WASABI_SECRET", getenv('WASABI_SECRET'));
define("WASABI_BUCKET", getenv('WASABI_BUCKET'));


class UserObject
{
    public $id;
    public $api_key;
    public $active;
    public $approved;
    public $recursiveUrlDownloadAllowed;
    public $ready;

    function __construct($sql, $submitted_api_key, $web = false)
    {
        $this->ready = false;
        $res = $sql->query(
            "SELECT id as id, api_key as api_key, active as active, approved as approved, recursive_url_download_allowed FROM tbl_users WHERE api_key='$submitted_api_key' LIMIT 1"
        );
        $row = $res->fetch_object();
        if ($row === null) {
            $this->id = null;
            $this->api_key = null;
            $this->active = false;
            $this->approved = null;
            $this->recursiveUrlDownloadAllowed = null;
        } else {
            $this->id = $row->id;
            $this->api_key = $row->api_key;
            $this->active = $row->active;
            $this->approved = $row->approved;
            $this->recursiveUrlDownloadAllowed = $row->recursive_url_download_allowed;
            $res->free_result();

            if ($web) {
                if ($this->active == 0) {
                    return false;
                }
                if ($this->approved == 0) {
                    return false;
                }
                $this->ready = true;
            }
            if ($this->active == 0) {
                http_response_code(401);
                usleep(500000);
                die("Error 14000 (Account not activated)");
            }
            if ($this->approved == 0) {
                http_response_code(401);
                usleep(500000);
                die("Error 14001 (Account not approved)");
            }

            $this->ready = true;
        }
    }

}

class ServerObject
{
    public $host_ip;

    public $sample;
    public $filename;

    public $sql;

    public $uri_api_key;
    public $uri_action;
    public $uri_hash;
    public $uri_type;
    public $uri_query;


    public $vars_dirty_root;

    // DB Tables
    public $vars_table_samples;
    public $vars_table_users;
    public $vars_table_sources;
    public $vars_table_searches;
    public $vars_table_pub_searches;
    public $vars_table_uploads;
    public $vars_table_url_download_tasks;
    public $vars_table_sample_partners;

    public $vt_context_key;
    public $vt_context_url;

    public $table;

    public $s3Client;

    function __construct()
    {
        $this->s3Client = new Aws\S3\S3Client([
            'credentials' => ['key' => WASABI_KEY, 'secret' => WASABI_SECRET],
            'endpoint' => WASABI_ENDPOINT,
            'region' => WASABI_REGION,
            'version' => 'latest',
            'use_path_style_endpoint' => true,
        ]);
        $this->host_ip = $_SERVER['REMOTE_ADDR'];

        if (defined('DB_CA_PATH')) {
            $this->sql = mysqli_init();
            $this->sql->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            $this->sql->ssl_set(null, null, DB_CA_PATH, null, null);
            $this->sql->real_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE, DB_PORT);
        } else {
            $this->sql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
        }


        if (mysqli_connect_errno()) {
            http_response_code(503);
//            printf("Connect failed: %s\n", mysqli_connect_error());
            die("Error 13000 (System Unavailable. Please report to admin@malshare.com)");
        }

        if (isset($_COOKIE['mapi_key']) && $_COOKIE['mapi_key'] != "") {
            $this->uri_api_key = $this->secure($_COOKIE['mapi_key']);
        } elseif (isset($_REQUEST["api_key"])) {
            $this->uri_api_key = $this->secure($_REQUEST["api_key"]);
        } else {
            $this->uri_api_key = null;
        }
        $this->uri_action = isset($_REQUEST["action"]) ? $this->secure($_REQUEST["action"]) : null;
        $this->uri_hash = isset($_REQUEST["hash"]) ? $this->secure(strtolower($_REQUEST["hash"])) : null;
        $this->uri_query = isset($_REQUEST["query"]) ? $this->secure(strtolower($_REQUEST["query"])) : null;
        $this->uri_private = isset($_REQUEST["private"]) ? $this->secure(strtolower($_REQUEST["private"])) : null;
        $this->uri_type = isset($_REQUEST["type"]) ? $this->secure(strtolower($_REQUEST["type"])) : null;
        $this->uri_path = isset($_REQUEST["path"]) ? $this->secure($_REQUEST["path"]) : null;
        $this->filename = isset($_REQUEST["hash"]) ? $this->secure(strtolower($_REQUEST["hash"])) : null;

        if (isset($_REQUEST["api_key"])) {
            if (preg_match("/[A-Za-z0-9]/", $_REQUEST["api_key"])) {
                if (! preg_match("/[A-Za-z0-9]/", $this->uri_api_key)) {
                    http_response_code(400);
                    die("No API Key Supplied");
                }
            }
        }

        // Paths
        $this->vars_dirty_root = UPLOAD_SAMPLES_ROOT;

        // Tables
        $this->vars_table_samples = SAMPLES_TABLE;
        $this->vars_table_users = USERS_TABLE;
        $this->vars_table_sources = SAMPLE_SOURCES_TABLE;
        $this->vars_table_searches = SEARCHES_TABLE;
        $this->vars_table_pub_searches = PUBSEARCHES_TABLE;
        $this->vars_table_uploads = UPLOADS_TABLE;
        $this->vars_table_url_download_tasks = URLDLTASKS_TABLE;
        $this->vars_table_sample_partners = SAMPLE_PARTNER_TABLE;

        $this->vt_context_key = VT_CONTEXT_KEY;
        $this->vt_context_url = VT_CONTEXT_URL;
    }

    public function login()
    {
        $uuser = new UserObject($this->sql, $this->uri_api_key, true);

        return $uuser;
    }

    public function secure($string)
    {
        if (! $this->sql) {
            die("ERROR");
        }
        $string = strip_tags($string);
        $string = stripslashes($string);

        $string = $this->sql->real_escape_string($string);

        return $string;
    }

    public function error_die($string)
    {
        http_response_code(500);
        usleep(500000);
        die($string);
    }

    public function redirect($loc)
    {
        http_response_code(302);
        header('Location: ' . $loc);

        return null;
    }

    public function error_die_with_code($code, $string)
    {
        http_response_code($code);
        usleep(500000);
        die($string);
    }

    public function load_context($hash)
    {
        $r_hash = $this->secure($hash);
        $hash = preg_replace("/[^a-zA-Z0-9]+/", "", $r_hash);

        $vt_key = $this->vt_context_key;

        $options = array(
            'http' => array(
                'header' => "x-apikey: " . $vt_key . "\r\n",
                'method' => 'GET',
            ),
        );

        $url = $this->vt_context_url . $hash;
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return false;
        }
        $vt_widget = json_decode($result);
        $widget = '  <iframe sandbox="allow-same-origin allow-scripts allow-popups allow-forms" src="' . $vt_widget->{'data'}->{'url'} . '"
          width="100%" height="500" allowfullscreen>
    <p>
      <a href="/en-US/docs/Glossary">
         VT Context:
      </a>
    </p>
  </iframe>';

        return $widget;


    }


    public function get_total()
    {
        $table = $this->vars_table_samples;
        $res = $this->sql->query("SELECT count(id) as rcount from $table ");
        if (! $res) {
            $this->error_die("Unable to get total sample count ");
        }

        $row = $res->fetch_object();

        return $row->rcount;
    }

    public function get_recent()
    {

        $table = $this->vars_table_samples;
        $table_sources = $this->vars_table_sources;
        $table_sample_partners = $this->vars_table_sample_partners;;

        $res = $this->sql->query(
            "SELECT id from $table WHERE ( ( pending != 1 or pending is NULL ) AND ftype != 'html' ) ORDER by added DESC limit 10"
        );

        if (! $res) {
            $this->error_die("Error 13513 (Unable to get recent samples. Please contact admin@malshare.com)");
        }

        $output = '<table class="table table-bordered table-striped" style="table-layout: fixed;">
        <thead>  <tr>
        <th style="width: 17%;">SHA256 Hash</th>
        <th style="width: 5%">File type</th>
        <th style="width: 13%">Added</th>
        <th style="width: 25%">Source</th>
        <th style="width: 40%">Yara Hits</th>
        </tr>  </thead>  <tbody>';

        while ($s_row = $res->fetch_object()) {
            $tQuery = "
                SELECT
                       $table.sha256 AS sha256,
                       $table.added AS added,
                       $table.ftype AS ftype,
                       $table.yara AS yara,
                       $table_sources.source AS source,
                       $table_sample_partners.display_name AS source_display_name
                FROM $table
                    LEFT JOIN $table_sources ON $table.id = $table_sources.id
                    LEFT JOIN $table_sample_partners ON $table_sources.sample_partner_submission = $table_sample_partners.id
                WHERE $table.id=" . $s_row->id;

            $r_res = $this->sql->query($tQuery);


            if (! $r_res) {
                $this->error_die(
                    "Error 13512 (Problem getting recent sample details.  Please contact admin@malshare.com)"
                );
            }
            if ($r_res->num_rows == 0) {
                next();
            }

            $sample_row = $r_res->fetch_object();

            $yhits = "";
            $jhits = json_decode($sample_row->yara);
            $counter = 0;
            $extend = 0;
            if (is_array($jhits->yara) || is_object($jhits->yara)) {
                foreach ($jhits->yara as $yh) {
                    $counter += 1;
                    if ($counter > 3 && $extend == 0) {
                        $yhits .= '<a id="c_yara_' . $sample_row->sha256 . '" class="none" href="#" onclick="document.getElementById(\'yara_' . $sample_row->sha256 . '\').style= \'block\'; document.getElementById(\'c_yara_' . $sample_row->sha256 . '\').className = \'hidden\';">[+]</a>';
                        $yhits .= '<div id="yara_' . $sample_row->sha256 . '" style="display: none;">';


                        $extend = 1;
                    }
                    $yhits .= '<a href="search.php?query=' . $yh . '"><span class="label label-info">' . $yh . '</span></a>  ';
                }

                if ($counter > 3) {
                    $yhits .= "</div>";
                }
            }
            $output .= '<tr>
                    <td class="hash_font"><div style = "word-wrap: break-word"><a href="sample.php?action=detail&hash=' . $sample_row->sha256 . '">' . $sample_row->sha256 . '</a></div></td>
                    <td>' . $sample_row->ftype . '</td>
                    <td>' . date("Y-m-d H:i:s", $sample_row->added) . ' UTC</td>';

            $output .= '<td class="word-wrap: wrap-word">' . $this->sourceForDisplay($sample_row) . '</td> ';
            $output .= '<td>' . $yhits . '</td></tr>';

        }
        $output .= '</tbody></table>';

        return $output;
    }

    public function sourceForDisplay($row, $separator = ' | ')
    {
        if ($row->source) {
            if ($row->source_display_name) {
                return $row->source . $separator . $row->source_display_name;
            } else {
                return $row->source;
            }
        } else {
            if (isset($row->source_display_name) && $row->source_display_name) {
                return $row->source_display_name;
            } else {
                return 'User Submission';
            }
        }
    }

    public function get_sitemap()
    {
        $output = "";
        $table = $this->vars_table_samples;
        $table_sources = $this->vars_table_sources;

        $res = $this->sql->query(
            "SELECT id from $table WHERE ( pending != 1 or pending is NULL ) ORDER by added DESC limit 5000"
        );

        if (! $res) {
            $this->error_die("Error 23214 (Problem building sitemap.  Please contact admin@malshare.com)");
        }
        while ($s_row = $res->fetch_object()) {
            $r_res = $this->sql->query(
                "SELECT $table.md5 as md5, $table.sha1 as sha1, $table.sha256 as sha256 FROM $table WHERE $table.id=" . $s_row->id
            );

            if (! $r_res) {
                $this->error_die("Error 23215 (Problem building sitemap details. Please contact admin@malshare.com)");
            }
            if ($r_res->num_rows == 0) {
                next();
            }

            $sample_row = $r_res->fetch_object();
            $output .= '<a href="sample.php?action=detail&hash=' . $sample_row->sha256 . '">' . $sample_row->md5 . ' | ' . $sample_row->sha1 . ' | ' . $sample_row->sha256 . '</a><br />';
        }

        return $output;
    }

    private function getRuleIdByName($ruleName)
    {
        if (! ($stmt = $this->sql->prepare('SELECT id FROM tbl_yara WHERE (lower(rule_name) = lower(?))'))) {
            return null;
        }
        $stmt->bind_param('s', $ruleName);
        $stmt->execute();
        $stmt->bind_result($yaraRuleId);
        $stmt->fetch();

        return $yaraRuleId;
    }

    public function sample_search($api_query = false)
    {
        $table = $this->vars_table_samples;
        $table_sources = $this->vars_table_sources;
        $table_searches = $this->vars_table_searches;
        $table_pub_searches = $this->vars_table_pub_searches;
        $table_sample_partners = $this->vars_table_sample_partners;

        $searchValue = $this->secure($this->uri_query);
        $searchPrivate = 0;
        $source_ip = $this->secure($_SERVER['REMOTE_ADDR']);
        if ($this->secure($this->uri_private) == "on") {
            $searchPrivate = 1;
        }

        if (strlen($searchValue) < 3) {
            $this->error_die("Query must by longer then 3 characters");
        }

        $src_sql_query = "INSERT INTO $table_searches (query, source, ts, private ) VALUES ( '$searchValue', '$source_ip', UNIX_TIMESTAMP(), '$searchPrivate' )";
        $this->sql->query($src_sql_query);
        $this->sql->commit();

        if ($api_query == false) {
            # If search by hash, just take users to the sample details page
            if (strlen($searchValue) == 32) {
                return $this->redirect("sample.php?action=detail&hash=" . $searchValue);
            } elseif (strlen($searchValue) == 40) {
                return $this->redirect("sample.php?action=detail&hash=" . $searchValue);
            } elseif (strlen($searchValue) == 64) {
                return $this->redirect("sample.php?action=detail&hash=" . $searchValue);
            }
        }

        if (substr($searchValue, 0, 7) == "source:") {
            $rhash = trim(explode(":", $searchValue)[1]);
            $res = $this->sql->query("SELECT distinct(id) from $table_sources where source like '%$rhash%' LIMIT 1");
        } else {
            if (substr($searchValue, 0, 4) == "yrp/") { // startswith
                $yaraId = $this->getRuleIdByName(substr($_REQUEST["query"], 4));
                if (! $yaraId) {
                    return '<p>YARA rule with this name could not be found</p>';
                }
                $sql = 'SELECT s.id FROM tbl_samples s LEFT JOIN tbl_matches m ON (s.id = m.sample_id) WHERE (m.yara_id = ' . $yaraId . ') ORDER BY s.added DESC LIMIT 100';
                $res = $this->sql->query($sql);
            } else {
                $searchValueLower = strtolower($searchValue);
                $res = $this->sql->query(
                    "SELECT id FROM tbl_sample_sources WHERE source LIKE '$searchValueLower%' LIMIT 100"
                );
            }
        }

        if (! $res) {
            $this->error_die("Error 13843 (System error while searching.  Please contact admin@malshare.com)");
        }

        // Build header / if not API
        if ($api_query == false) {
            $output = '<table class="table table-bordered table-striped" style="table-layout: fixed;">
        <thead>  <tr>
        <th style="width: 17%;">SHA256 Hash</th>
        <th style="width: 5%">File type</th>
        <th style="width: 13%">Added</th>
        <th style="width: 25%">Source</th>
        <th style="width: 40%">Yara Hits</th>
        </tr>  </thead>  <tbody>';
        } else {
            header('Content-Type: application/json');
            $output = array();
        }
        // Fetch data
        $totalHits = 0;
        while ($s_row = $res->fetch_object()) {
            $r_res = $this->sql->query(
                "
                SELECT
                       $table.id AS id,
                       $table.md5 AS md5,
                       $table.sha1 AS sha1,
                       $table.sha256 AS sha256,
                       $table.added AS added,
                       $table.ftype AS ftype,
                       $table.yara AS yara,
                       $table_sources.source AS source,
                       $table_sample_partners.display_name AS display_name_source,
                       $table.parent_id
                FROM $table
                    LEFT JOIN $table_sources ON $table.id = $table_sources.id
                    LEFT JOIN $table_sample_partners ON $table_sources.sample_partner_submission = $table_sample_partners.id
                WHERE $table.id=" . $s_row->id
            );

            if (! $r_res) {
                $this->error_die("Error 13842 (Problem fetching search results.  Please contact admin@malshare.com)");
            }
            if ($r_res->num_rows == 0) {
                next();
            }

            $sample_row = $r_res->fetch_object();
            $totalHits += 1;
            $source = $this->sourceForDisplay($sample_row, '<br/>');

            // if not an API query, build HTML
            if ($api_query == false) {
                $output .= '<tr>
                    <td class="hash_font"><div style = "word-wrap: break-word"><a href="sample.php?action=detail&hash=' . $sample_row->sha256 . '">' . $sample_row->sha256 . '</a></div></td>
                    <td>' . $sample_row->ftype . '</td>
                    <td>' . date("Y-m-d H:i:s", $sample_row->added) . '</td>';

                if (strlen($source) > 45) {
                    $output .= '<td>' . substr($source, 0, 45) . '...</td> ';
                } else {
                    $output .= '<td>' . $source . '</td> ';
                }

                $yhits = "";
                $jhits = json_decode($sample_row->yara);

                if (is_array($jhits->yara) || is_object($jhits->yara)) {
                    $extend = 0;
                    $counter = 0;
                    foreach ($jhits->yara as $yh) {
                        $counter += 1;
                        if ($counter > 4 && $extend == 0) {

                            $yhits .= '<a id="c_yara_' . $sample_row->sha256 . '" class="none" href="#" onclick="document.getElementById(\'yara_' . $sample_row->sha256 . '\').style= \'block\'; document.getElementById(\'c_yara_' . $sample_row->sha256 . '\').className = \'hidden\';">[+]</a>';
                            $yhits .= '<div id="yara_' . $sample_row->sha256 . '" style="display: none;">';

                            $extend = 1;
                        }
                        $yhits .= '<a href="search.php?query=' . $yh . '"><span class="label label-info">' . $yh . '</span></a>  ';
                    }
                    if ($counter > 4) {
                        $yhits .= "</div>";
                    }
                }
                $output .= '<td>' . $yhits . '</td></tr>';
                $output .= '</tr>';
            } else {
                $t = array(
                    //                    'id' => $sample_row->id,
                    //                    'parentid' => $sample_row->parent_id,
                    'md5' => $sample_row->md5,
                    'sha1' => $sample_row->sha1,
                    'sha256' => $sample_row->sha256,
                    'type' => $sample_row->ftype,
                    'added' => intval($sample_row->added),
                    'source' => $source,
                    'yarahits' => json_decode($sample_row->yara),
                    'parentfiles' => array(),
                    'subfiles' => array(),
                );

                if (($sample_row->parent_id != null)) {
                    if (strpos($sample_row->parent_id, ',') !== false) {
                        $parent_ids = explode(",", $sample_row->parent_id);
                    } else {
                        $parent_ids = array($sample_row->parent_id);
                    }

                    foreach ($parent_ids as $pid) {
                        $full_res = $this->sql->query("SELECT md5, sha1, sha256 FROM $table WHERE id = " . $pid);
                        if (! $full_res) {
                            $this->error_die(
                                "Error 138413 (Problem getting sample parents. Please contact admin@malshare.com)"
                            );
                        }
                        if (! $full_res->num_rows == 0) {
                            while ($s_row = $full_res->fetch_object()) {
                                array_push(
                                    $t['parentfiles'],
                                    array('md5' => $s_row->md5, 'sha1' => $s_row->sha1, 'sha256' => $s_row->sha256)
                                );
                            }
                        }
                    }
                }

                $full_res = $this->sql->query(
                    "SELECT md5, sha1, sha256 FROM $table WHERE parent_id = " . $sample_row->id
                );
                if (! $full_res) {
                    die("Error 13849 ( Problem getting child files. Please contact admin@malshare.com)");
                }
                if (! $full_res->num_rows == 0) {
                    while ($s_row = $full_res->fetch_object()) {
                        array_push(
                            $t['subfiles'],
                            array('md5' => $s_row->md5, 'sha1' => $s_row->sha1, 'sha256' => $s_row->sha256)
                        );
                    }
                }

                #$output .= json_encode($t, JSON_UNESCAPED_SLASHES);
                array_push($output, $t);
            }
        }

        if (($api_query == false) && ($totalHits > 0) && ($searchPrivate == 0)) {
            $src_sql_query = "INSERT INTO $table_pub_searches (query, ts ) VALUES ( '$searchValue',  UNIX_TIMESTAMP() )";
            $res = $this->sql->query($src_sql_query);
            $this->sql->commit();
        }

        if ($api_query == false) {
            $output .= '</tbody></table>  ';

            return $output;
        } else {
            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
    }

    public function get_details()
    {
        $r_hash = $this->uri_hash;
        $hash = preg_replace("/[^a-zA-Z0-9]+/", "", $r_hash);

        $table = $this->vars_table_samples;
        $table_sources = $this->vars_table_sources;
        $table_sample_partners = $this->vars_table_sample_partners;
        $table_uploads = $this->vars_table_uploads;


        if (strlen($hash) == 32) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE md5 = lower('$hash')");
        } elseif (strlen($hash) == 40) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE sha1 = lower('$hash')");
        } elseif (strlen($hash) == 64) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE sha256 = lower('$hash')");
        } else {
            http_response_code(404);
            usleep(500000);
            die("Invalid Hash.");
        }
        if (! $res) {
            die("Error 13417 (Problem findings sample details.  Please contact admin@malshare.com)");
        }
        if ($res->num_rows == 0) {
            usleep(500000);
            http_response_code(404);
            die("Sample not found with hash ( $hash )");
        }

        $row = $res->fetch_object();

        $full_res = $this->sql->query(
            "SELECT md5, sha1, sha256, ssdeep, added, ftype, yara, pending, parent_id FROM $table WHERE id = " . $row->hash
        );
        if (! $full_res) {
            $this->error_die("Error 23418 (Unable to find child samples  Please contact admin@malshare.com)");
        }
        if ($full_res->num_rows == 0) {
            http_response_code(404);
            usleep(500000);
            die("Error Sample not found by hash ($hash)");
        }
        $f_row = $full_res->fetch_object();

        $dt = new DateTime("@$f_row->added");

        $output = '<br />
            <button type"submit">
            <a href="sampleshare.php?action=getfile&hash=' . $f_row->sha256 . '">Download</a></button>

            <table class="table">
            <thead>
              <tr>
                <th>Hashes</th>

              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="hash_font"><b>MD5</b>:   ' . $f_row->md5 . '</td>
              </tr>
              <tr>
                <td class="hash_font"><b>SHA1</b>:   ' . $f_row->sha1 . '</td>
              </tr>
              <tr>
                <td class="hash_font"><b>SHA256</b>:   ' . $f_row->sha256 . '</td>
              </tr>
              <tr>
                <td class="hash_font"><b>SSDEEP</b>:   ' . $f_row->ssdeep . '</td>
              </tr>
            </tbody>
            </table>
        ';
        $fname_search = $this->sql->query("SELECT name FROM $table_uploads WHERE md5 = '" . $f_row->md5 . "'");
        if (! $fname_search) {
            $this->error_die("Error 23428 (Unable to find file names  Please contact admin@malshare.com)");
        }
        if ($fname_search->num_rows >= 0) {
            $output .= '<table class="table"><thead><tr><th>Observed File Names</th></tr></thead><tbody>';
            while ($trow = $fname_search->fetch_object()) {
                $output .= '<tr><td>' . $trow->name . '</td> </tr>';
            }
            $output .= '</tbody></table>';
        }
        $output .= '<table class="table"><thead><tr><th>Yara Hits</th></tr></thead><tbody><tr><td>';
        $jhits = json_decode($f_row->yara);
        if ($jhits && (is_array($jhits->yara) || is_object($jhits->yara))) {
            foreach ($jhits->yara as $yh) {
                $output .= '<span class="label label-info">' . $yh . '</span> | ';
            }
        }

        $output .= " </td></tr>
        </tbody>
        </table>";

        if ($f_row->parent_id != null and $f_row->parent_id != -1) {
            $output .= '
                <table class="table">
                <thead>
                        <tr>
                                <th>Parent Files</th>
                        </tr>
                </thead>
                <tbody>
            ';

            if (strpos($f_row->parent_id, ',') !== false) {
                $parent_ids = explode(",", $f_row->parent_id);
            } else {
                $parent_ids = array($f_row->parent_id);
            }

            foreach ($parent_ids as $pid) {
                $full_res = $this->sql->query("SELECT sha256 FROM $table WHERE id = " . $pid);
                if (! $full_res) {
                    $this->error_die(
                        "Error 23732 (Problem finding parent details for hash.  Please contact admin@malshare.com)"
                    );
                }
                if (! $full_res->num_rows == 0) {
                    while ($s_row = $full_res->fetch_object()) {
                        $output .= '<tr> <td><a href="sample.php?action=detail&hash=' . $s_row->sha256 . '">' . $s_row->sha256 . '</a></td> </tr>';
                    }
                    $output .= '
                                    </tbody>
                            </table>
                    ';
                }
            }
        }
        $full_res = $this->sql->query("SELECT sha256 FROM $table WHERE parent_id = " . $row->hash);
        if (! $full_res) {
            $this->error_die("Error 23734 (Problem finding child samples.  Please contact admin@malshare.com)");
        }
        if (! $full_res->num_rows == 0) {
            $output .= '
                    <table class="table">
                            <thead>
                                    <tr>
                                            <th>Sub Files</th>
                                    </tr>
                            </thead>
                            <tbody>
            ';
            while ($s_row = $full_res->fetch_object()) {
                $output .= '<tr> <td><a href="sample.php?action=detail&hash=' . $s_row->sha256 . '">' . $s_row->sha256 . '</a></td> </tr>';
            }
            $output .= '
                            </tbody>
                    </table>
            ';
        }

        $full_res = $this->sql->query(
            "
            SELECT
                $table_sources.source AS source,
                $table_sample_partners.display_name AS source_display_name
            FROM $table_sources
            LEFT JOIN $table_sample_partners ON $table_sources.sample_partner_submission = $table_sample_partners.id
            WHERE $table_sources.id = " . $row->hash
        );
        if (! $full_res) {
            $this->error_die("Error 23735 (Problem finding sources for sample.  Please contact admin@malshare.com)");
        }
        if (! $full_res->num_rows == 0) {
            $output .= '
                <table class="table">
                    <thead>
                        <tr>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                ';
            while ($s_row = $full_res->fetch_object()) {
                $output .= '<tr><td>' . $this->sourceForDisplay($s_row) . '</td></tr>';
            }
            $output .= '</tbody></table>';
        }

// VT Context:
        $vt_context = $this->load_context($hash);
        if ($vt_context != false) {
            $output .= '<table class="table"><thead><tr><th>VT Context</th></tr></thead><tbody><tr><td>';
            $output .= $vt_context;
            $output .= " </td></tr></tbody></table>";

        }
        if ($f_row->pending == 1) {
            $output .= "<script>ShowLoading();</script>";
        }

        return $output;

    }

    public function get_details_json()
    {
        header('Content-Type: application/json');
        $output = array();

        $r_hash = $this->uri_hash;
        $hash = preg_replace("/[^a-zA-Z0-9]+/", "", $r_hash);

        $table = $this->vars_table_samples;
        $table_sources = $this->vars_table_sources;
        $table_uploads = $this->vars_table_uploads;

        if (strlen($hash) == 32) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE md5 = lower('$hash')");
        } elseif (strlen($hash) == 40) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE sha1 = lower('$hash')");
        } elseif (strlen($hash) == 64) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE sha256 = lower('$hash')");
        } else {
            http_response_code(400);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 400;
            $output['ERROR']["MESSAGE"] = "Invalid Hash";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 724433;
            $output['ERROR']["MESSAGE"] = "Problem finding sample details for json details.  Please contact admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
        if ($res->num_rows == 0) {
            http_response_code(404);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 404;
            $output['ERROR']["MESSAGE"] = "Sample not found";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
        $row = $res->fetch_object();

        $full_res = $this->sql->query(
            "SELECT md5, sha1, sha256, ssdeep, added, ftype FROM $table WHERE id = " . $row->hash
        );

        if (! $full_res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 724341;
            $output['ERROR']["MESSAGE"] = "problem getting details for hash (json).  Please contact admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
        if ($full_res->num_rows == 0) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 500;
            $output['ERROR']["MESSAGE"] = "Sample details not found";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        $f_row = $full_res->fetch_object();
        $output['MD5'] = $f_row->md5;
        $output['SHA1'] = $f_row->sha1;
        $output['SHA256'] = $f_row->sha256;
        $output['SSDEEP'] = $f_row->ssdeep;
        $output['F_TYPE'] = $f_row->ftype;

        $full_res = $this->sql->query("SELECT source FROM $table_sources WHERE id = " . $row->hash);
        if (! $full_res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 724323;
            $output['ERROR']["MESSAGE"] = "Problem getting sources for hash.  Please contact admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
        $t_source = array();
        while ($s_row = $full_res->fetch_object()) {
            array_push($t_source, $s_row->source);
        }

        $name_res = $this->sql->query("SELECT name FROM $table_uploads WHERE md5 = '" . $f_row->md5 . "'");
        if (! $name_res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 724323;
            $output['ERROR']["MESSAGE"] = "Problem getting sources for hash.  Please contact admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }
        $t_names = array();
        while ($f_row = $name_res->fetch_object()) {
            array_push($t_names, $f_row->name);
        }

        $output['FILENAMES'] = $t_names;

        return json_encode($output, JSON_UNESCAPED_SLASHES);
    }

    public function get_hashes(array $hashes)
    {
        $sha256s = [];
        $sha1s = [];
        $md5s = [];
        foreach ($hashes as &$hash) {
            $hash = trim(strtolower($hash));
            if (preg_match('/^[a-f0-9]{32}$/', $hash)) {
                $md5s[] = $hash;
            } elseif (preg_match('/^[a-f0-9]{40}$/', $hash)) {
                $sha1s[] = $hash;
            } elseif (preg_match('/^[a-f0-9]{64}$/', $hash)) {
                $sha256s[] = $hash;
            }
        }
        $where = [];
        if ($md5s) {
            $where[] = '(md5 IN ("' . implode('", "', $md5s) . '"))';
        }
        if ($sha1s) {
            $where[] = '(sha1 IN ("' . implode('", "', $sha1s) . '"))';
        }
        if ($sha256s) {
            $where[] = '(sha256 IN ("' . implode('", "', $sha256s) . '"))';
        }
        if (! $where) {
            return [];
        }
        $sql = 'SELECT sha256, md5, sha1 FROM ' . $this->vars_table_samples .
            ' WHERE (' . implode(' OR ', $where) . ')';
        if (! ($stmt = $this->sql->prepare($sql))) {
            return [];
        }
        $stmt->execute();
        $stmt->bind_result($sha256, $md5, $sha1);
        $ret = [];
        while ($stmt->fetch()) {
            $ret[] = [
                'sha256' => $sha256,
                'md5' => $md5,
                'sha1' => $sha1,
            ];
        }

        return $ret;
    }

    private function sample_key($hash)
    {
        $part1 = substr($hash, 0, 3);
        $part2 = substr($hash, 3, 3);
        $part3 = substr($hash, 6, 3);

        return "$part1/$part2/$part3/$hash";
    }

    public function get_sample_url($hash)
    {
        if ($hash == "") {
            $this->error_die("Empty hash specified");
        }

        switch (strlen($hash)) {
            case 32:
                $searchFieldName = 'md5';
                break;
            case 40:
                $searchFieldName = 'sha1';
                break;
            case 64:
                $searchFieldName = 'sha256';
                break;
            default:
                http_response_code(404);
                usleep(500000);
                die("Invalid Hash...");
        }
        $res = $this->sql->query(
            "SELECT sha256 AS hash, md5 AS md5 FROM $this->vars_table_samples WHERE " . $searchFieldName . " = lower('$hash')"
        );

        if (! $res) {
            die("Error 13940 (Problem finding sample.  Please contact admin@malshare.com)");
        }
        if ($res->num_rows == 0) {
            http_response_code(404);
            usleep(500000);
            die("Sample not found by hash ($hash)");
        }
        $row = $res->fetch_object();
        if ($row->hash == "" || $row->md5 == "") {
            http_response_code(404);
            usleep(500000);
            die("Sample not found by hash ($hash)");
        }
        if (! $row->hash) {
            http_response_code(400);
            die("No hash specified in `hash` GET variable");
        }

        $s3Key = $this->sample_key($row->hash);

        if (! $this->s3Client->doesObjectExist(WASABI_BUCKET, $s3Key)) {
            http_response_code(404);
            die("Error 12413 (Sample Missing. Please alert admin@malshare.com @ " . WASABI_REGION . "): " . WASABI_BUCKET . ' ' . $s3Key);
        }
        $cmd = $this->s3Client->getCommand('GetObject', ['Bucket' => WASABI_BUCKET, 'Key' => $s3Key]);
        $request = $this->s3Client->createPresignedRequest($cmd, '+5 minutes');

        return (string)$request->getUri();
    }

    public function send_headers($filename)
    {
        header("Pragma: public\n");
        header("Content-Type: application/octet-stream\n");
        header("Content-Disposition: attachment; filename=$filename\n");
        header("Content-transfer-encoding: binary\n");
    }

    public function get_list()
    {
        header('Content-Type: application/json');
        $output = array();

        $table = $this->vars_table_samples;

        $res = $this->sql->query(
            "SELECT md5 as md5, sha1 as sha1, sha256 as sha256 FROM $table WHERE ( added > ( UNIX_TIMESTAMP() - 86400) ) "
        );
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 131312;
            $output['ERROR']["MESSAGE"] = "Unable to generate sample list.  Please report to admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        while ($row = $res->fetch_object()) {
            array_push($output, array('md5' => $row->md5, 'sha1' => $row->sha1, 'sha256' => $row->sha256));
        }

        return json_encode($output);
    }

    public function get_list_raw()
    {
        $table = $this->vars_table_samples;

        $res = $this->sql->query(
            "SELECT md5 as md5, sha1 as sha1, sha256 as sha256 FROM $table WHERE ( added > ( UNIX_TIMESTAMP() - 86400) ) "
        );
        if (! $res) {
            $this->error_die("Error 131311 (Please report to admin@malshare.com)");
        }

        while ($row = $res->fetch_object()) {
            print("$row->md5 $row->sha1 $row->sha256\n");
        }
    }

    public function sample_details_raw($hash)
    {
        $output = array();

        $table = $this->vars_table_samples;
        $table_sources = $this->vars_table_sources;

        if (strlen($hash) == 32) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE md5 = lower('$hash')");
        } elseif (strlen($hash) == 40) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE sha1 = lower('$hash')");
        } elseif (strlen($hash) == 64) {
            $res = $this->sql->query("SELECT id as hash FROM $table WHERE sha256 = lower('$hash')");
        } else {
            http_response_code(404);
            $this->error_die("Invalid Hash.");
        }

        if (! $res) {
            $this->error_die("Error 139491 (Problem pulling sample record. Please contact admin@malshare.com)");
        }
        if ($res->num_rows == 0) {
            http_response_code(404);
            usleep(500000);
            die("Sample not found by hash ($hash)");
        }
        $row = $res->fetch_object();


        $full_res = $this->sql->query(
            "SELECT md5, sha1, sha256, ssdeep, added, ftype FROM $table WHERE id = " . $row->hash
        );
        if (! $full_res) {
            $this->error_die("Error 139432 (Problem getting sample details. Please contact admin@malshare.com)");
        }
        if ($full_res->num_rows == 0) {
            http_response_code(404);
            usleep(500000);
            die("Sample not found by hash ($hash)");
        }
        $f_row = $full_res->fetch_object();
        $output['MD5'] = $f_row->md5;
        $output['SHA1'] = $f_row->sha1;
        $output['SHA256'] = $f_row->sha256;
        $output['SSDEEP'] = $f_row->ssdeep;
        $output['F_TYPE'] = $f_row->ftype;
        $output['ADDED'] = $f_row->added;

        $full_res = $this->sql->query("SELECT source FROM $table_sources WHERE id = " . $row->hash);
        if (! $full_res) {
            $this->error_die("Error 139312 (Problem sample sources. Please contact admin@malshare.com)");
        }
        if ($full_res->num_rows == 0) {
            http_response_code(404);
            usleep(500000);
            die("Sample not found by hash ($hash)");
        }
        $t_source = array();
        while ($s_row = $full_res->fetch_object()) {
            array_push($t_source, $s_row->source);
        }
        $output['SOURCES'] = $t_source;

        return $output;
    }


    public function get_sum()
    {
        header('Content-Type: application/json');

        $output = array();
        $table = $this->vars_table_samples;

        $res = $this->sql->query("SELECT sha256 as sha256 FROM $table WHERE ( added > ( UNIX_TIMESTAMP() - 86400) ) ");
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 139001;
            $output['ERROR']["MESSAGE"] = "Problem pulling sample count.  Please report to admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        while ($row = $res->fetch_object()) {
            array_push($output, $this->sample_details_raw($row->sha256));
        }

        return json_encode($output);
    }

    public function search_type_day()
    {
        header('Content-Type: application/json');

        $results = array();

        $table = $this->vars_table_samples;

        $r_type = $this->uri_type;

        $type = preg_replace("/[^a-zA-Z0-9]+/", "", $r_type);

        $res = $this->sql->query(
            "SELECT md5 as md5, sha1 as sha1, sha256 as sha256 FROM $table WHERE ( added > ( UNIX_TIMESTAMP() - 86400) and lower(ftype) = '$type') "
        );
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 131132;
            $output['ERROR']["MESSAGE"] = "Problem pulling results for the past day.  Please report to admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        while ($row = $res->fetch_object()) {
            array_push($results, array('md5' => $row->md5, 'sha1' => $row->sha1, 'sha256' => $row->sha256));
        }

        return json_encode($results);
    }

    public function get_types()
    {
        header('Content-Type: application/json');

        $output = array();
        $table = $this->vars_table_samples;

        $res = $this->sql->query(
            "SELECT ftype as ftype, count(id) as fcount from $table WHERE added > (unix_timestamp() - 86400) AND ftype != '-' GROUP BY ftype"
        );
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 138523;
            $output['ERROR']["MESSAGE"] = "Problem pulling types from the past day.  Please report to admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        while ($row = $res->fetch_object()) {
            $output[$row->ftype] = intval($row->fcount);
        }


        return json_encode($output, JSON_UNESCAPED_SLASHES);
    }

    public function get_filenames()
    {
        header('Content-Type: application/json');

        $output = array();
        $table = $this->vars_table_uploads;

        $res = $this->sql->query(
            "SELECT distinct name as name FROM $table WHERE ( ts > ( UNIX_TIMESTAMP()-86400) and ts is not NULL ) "
        );
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 138043;
            $output['ERROR']["MESSAGE"] = "Problem pulling sources for the past day.  Please report to admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        while ($row = $res->fetch_object()) {
            array_push($output, $row->name);
        }

        return json_encode($output, JSON_UNESCAPED_SLASHES);
    }

    public function get_sources()
    {
        header('Content-Type: application/json');

        $output = array();
        $table = $this->vars_table_sources;

        $res = $this->sql->query(
            "SELECT distinct source as source FROM $table WHERE ( added > ( UNIX_TIMESTAMP()-86400) and added is not NULL ) "
        );
        if (! $res) {
            http_response_code(500);
            $output['ERROR'] = array();
            $output['ERROR']["CODE"] = 138023;
            $output['ERROR']["MESSAGE"] = "Problem pulling sources for the past day.  Please report to admin@malshare.com";

            return json_encode($output, JSON_UNESCAPED_SLASHES);
        }

        while ($row = $res->fetch_object()) {
            array_push($output, $row->source);
        }

        return json_encode($output, JSON_UNESCAPED_SLASHES);
    }

    public function get_sources_raw()
    {
        $table = $this->vars_table_sources;

        $res = $this->sql->query(
            "SELECT distinct source as source FROM $table WHERE ( added > ( UNIX_TIMESTAMP()-86400) and added is not NULL ) "
        );
        if (! $res) {
            $this->error_die(
                "Error 138024. (Problem pulling raw source list for the past day. Please report to admin@malshare.com)"
            );
        }

        while ($row = $res->fetch_object()) {
            print("$row->source\n");
        }
    }

    public function get_user_limit()
    {
        header('Content-Type: application/json');
        $output = array();
        $table = $this->vars_table_users;
        $api_key = $this->uri_api_key;

        $res = $this->sql->query("SELECT query_limit, query_base FROM $table WHERE api_key= '$api_key' ");
        if (! $res) {
            http_response_code(500);
            $eoutput = array();
            $eoutput['ERROR'] = array();
            $eoutput['ERROR']["CODE"] = 439021;
            $eoutput['ERROR']["MESSAGE"] = "Unable to fetch limits.  Please report to admin@malshare.com";

            return json_encode($eoutput, JSON_UNESCAPED_SLASHES);
        }
        $row = $res->fetch_object();
        if ($row === null) {
            return null;
        }

        try {
            $output["LIMIT"] = $row->query_base;
            $output["REMAINING"] = $row->query_limit;
        } catch (Exception $user_limit_exception) {
            http_response_code(500);
            $eoutput = array();
            $eoutput['ERROR'] = array();
            $eoutput['ERROR']["CODE"] = 439022;
            $eoutput['ERROR']["MESSAGE"] = "Problem pulling sources for the past day.  Please report to admin@malshare.com";

            return json_encode($eoutput, JSON_UNESCAPED_SLASHES);
        }

        return json_encode($output, JSON_UNESCAPED_SLASHES);
    }

    public function update_query_limit()
    {
        $table = $this->vars_table_users;
        $api_key = $this->uri_api_key;

        $res = $this->sql->query("SELECT query_limit, last_query FROM $table WHERE api_key= '$api_key' ");
        if (! $res) {
            $this->error_die("Error 432101 (Please report to admin@malshare.com)");
        }
        $row = $res->fetch_object();

        if ($row->query_limit <= 0) {

            if (($row->last_query + 86400) < time()) {
                $res = $this->sql->query("UPDATE $table SET query_limit = query_base - 1  WHERE api_key= '$api_key' ");
                if (! $res) {
                    $this->error_die("Error 432103 (Please report to admin@malshare.com)");
                }

            } else {
                http_response_code(429);
                sleep(5);
                die("Error: Over Request Limit.  Please contact admin@malshare.com if you need this increased");
            }
        } else {
            $res = $this->sql->query(
                "UPDATE $table SET query_limit = query_limit - 1, last_query = UNIX_TIMESTAMP() WHERE api_key= '$api_key' "
            );
            if (! $res) {
                $this->error_die("Error 432104 (Please report to admin@malshare.com)");
            }

        }
    }

    public function increment_query_limit()
    {
        $table = $this->vars_table_users;
        $api_key = $this->uri_api_key;

        $res = $this->sql->query("UPDATE $table SET query_limit = query_limit + 1 WHERE api_key= '$api_key' ");
        if (! $res) {
            $this->error_die("Error 432104 (Please report to admin@malshare.com)");
        }
    }


    public function update_sample_count($hash)
    {
        $table = $this->vars_table_samples;
        $res = $this->sql->query("UPDATE $table SET counter = counter + 1 WHERE md5 = '$hash' ");
        if (! $res) {
            $this->error_die("Error 432201 (Please report to admin@malshare.com)");
        }
    }

    public function mark_processing($hash)
    {
        $table = $this->vars_table_samples;
        $res = $this->sql->query("UPDATE $table SET processed = 1 WHERE md5 = '$hash' ");
        if (! $res) {
            $this->error_die("Error 630001 (Please report to admin@malshare.com)");
        }
    }

    public function get_next_unprocessed()
    {
        $table = $this->vars_table_samples;

        $res = $this->sql->query("SELECT md5 as hash FROM $table where processed = 0 order by added limit 1;");
        if (! $res) {
            $this->error_die("Error 630002 (Please report to admin@malshare.com)");
        }
        if ($res->num_rows == 0) {
            $this->error_die("Error 63003 No samples waiting processing.");
        }

        $row = $res->fetch_object();

        return $row->hash;
    }

    public function stats_get_types()
    {
        $results = array();

        $table = $this->vars_table_samples;

        $res = $this->sql->query(
            "SELECT ftype as ftype, count(id) as fcount from $table WHERE added > (unix_timestamp() - 86400) AND ftype != '-' GROUP BY ftype limit 8"
        );
        if (! $res) {
            return "Error 132522 (Unable to list file types.  Please report to admin@malshare.com)";
        }
        if ($res->num_rows == 0) {
            return "Error 132523 (Unable to list file types.  Please report to admin@malshare.com)";
        }

        while ($row = $res->fetch_object()) {
            $results[$row->ftype] = $row->fcount;
        }

        return $results;
    }

    public function stats_get_top_rules()
    {
        $results = array();

        $table = $this->vars_table_samples;

        $res = $this->sql->query(
            "select yara->'$.yara' as rules from $table WHERE added > (unix_timestamp() - 86400) "
        );
        if (! $res) {
            return "Error 132621 (Unable to list file types.  Please report to admin@malshare.com)";
        }
        if ($res->num_rows == 0) {
            return "Error 132622 (Unable to list file types.  Please report to admin@malshare.com)";
        }

        while ($row = $res->fetch_object()) {
            $rules = json_decode($row->rules);
            foreach ($rules as $yhit) {
                // if ( strpos($yhit, '/contentis'
                array_push($results, $yhit);
            }
        }

        $totals = array_count_values($results);
        arsort($totals);
        $totals = array_slice($totals, 0, 10);

        return $totals;
    }

    public function get_recent_searches()
    {
        $results = array();

        $table = $this->vars_table_pub_searches;

        $res = $this->sql->query("SELECT query from $table  ORDER BY ts DESC limit 10");
        if (! $res) {
            return $results;
        }
        if ($res->num_rows == 0) {
            return $results;
        }

        while ($row = $res->fetch_object()) {
            array_push($results, $row->query);
        }

        return $results;
    }

    public function get_samples_count_date()
    {
        $results = array();

        $table = $this->vars_table_samples;

        $res = $this->sql->query(
            "SELECT FROM_UNIXTIME(added, \"%Y-%m-%d\") AS date, COUNT(*) AS sampleCount FROM $table WHERE ( added > ( unix_timestamp(now()) - 604800  ) )  GROUP BY FROM_UNIXTIME(added, \"%Y-%m-%d\") ORDER BY sampleCount DESC;"
        );
        if (! $res) {
            return $results;
        }
        if ($res->num_rows == 0) {
            return $results;
        }

        while ($row = $res->fetch_object()) {
            array_push($results, array($row->date, $row->sampleCount));
        }

        return $results;
    }

    public function sha256ExistsInDatabase($sha256): bool
    {
        $sql = "SELECT sha256 FROM {$this->vars_table_samples} where sha256 = ? limit 1";
        if (! ($stmt = $this->sql->prepare($sql))) {
            die('Error 148993. Please contact admin@malshare.com');
        }
        $stmt->bind_param('s', $sha256);
        $stmt->execute();
        $stmt->bind_result($hash);

        return !!$stmt->fetch();
    }

    public function upload_sample($uploadedSample): array
    {
        $tmpPath = $uploadedSample['tmp_name'];
        if (!$tmpPath) {
            return ['type' => 'error', 'message' => 'No file specified'];
        }
        $md5 = strtolower(hash_file("md5", "$tmpPath"));
        $sha1 = strtolower(hash_file("sha1", "$tmpPath"));
        $sha156 = strtolower(hash_file("sha256", "$tmpPath"));
        $s3Key = $this->sample_key($sha156);

        $remoteAddress = $this->secure($_SERVER['REMOTE_ADDR']);
        $clientFileName = $this->secure($uploadedSample['name']);

        try {
            $sql = "INSERT INTO {$this->vars_table_uploads} (name, md5, source, ts) VALUES (?, ?, ?, UNIX_TIMESTAMP())";
            if (! ($stmt = $this->sql->prepare($sql))) {
                return ['type' => 'error', 'message' => 'Error 148992. Please contact admin@malshare.com'];
            }
            $stmt->bind_param('sss', $clientFileName, $md5, $remoteAddress);
            $stmt->execute();

            if ($this->sha256ExistsInDatabase($sha156)) {
                if ($this->s3Client->doesObjectExist(WASABI_BUCKET, $s3Key)) {
                    return ['type' => 'success', 'sha256' => $sha156, 'message' => 'sample already exists'];
                }
            }

            $this->s3Client->putObject(['Bucket' => WASABI_BUCKET, 'Key' => $s3Key, 'SourceFile' => $tmpPath]);

            $sql = "INSERT INTO {$this->vars_table_samples} (md5, sha1, sha256, added, counter, pending,ftype) VALUES (?, ?, ?, UNIX_TIMESTAMP(), 0, 1, '-')";
            if (! ($stmt = $this->sql->prepare($sql))) {
                return ['type' => 'error', 'message' => 'Error 139999. Please report to admin@malshare.com'];
            }
            $stmt->bind_param('sss', $md5, $sha1, $sha156);
            $stmt->execute();
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }

        return ['type' => 'success', 'sha256' => $sha156];
    }

    public function task_url_download($user_id, $durl, $drecursive)
    {
        $table = $this->vars_table_url_download_tasks;

        $recursive = $this->secure($drecursive);
        $url = $this->secure($durl);

        # https://stackoverflow.com/questions/21671179/how-to-generate-a-new-guid
        $guid = vsprintf(
            '%s%s-%s-4000-8%.3s-%s%s%s0',
            str_split(dechex(microtime(true) * 1000) . bin2hex(random_bytes(8)), 4)
        );

        if ($recursive != 1) {
            $recursive = 0;
        }
        $sql_query = "INSERT INTO $table (guid, user_id, url, fetchall) VALUES ( '$guid', '$user_id', '$url', $recursive )";

        $res = $this->sql->query($sql_query);
        if (! $res) {
            $this->error_die("Error 149991 (URL Tasking failed. Please report to admin@malshare.com)");

            return "false";
        }

        return $guid;
    }

    public function is_valid_guid($guid)
    {
        if (! preg_match("/^[A-Fa-f0-9]{8}\-[A-Fa-f0-9]{4}\-4000-8[A-Fa-f0-9]{3}\-[A-Fa-f0-9]{12}$/", $guid)) {
            return false;
        }

        return true;
    }

    public function get_download_status($userId, $guid)
    {
        $table = $this->vars_table_url_download_tasks;
        $sql = 'SELECT started_at, finished_at FROM ' . $table . ' WHERE (guid = ?) AND (user_id = ?)';
        if (! ($stmt = $this->sql->prepare($sql))) {
            $this->error_die(
                "Error 149992 (Problem fetching URL Download task status.  Please contact admin@malshare.com)"
            );
        }
        $stmt->bind_param('si', $guid, $userId);
        $stmt->execute();
        $stmt->bind_result($startedAt, $finishedAt);
        if (! $stmt->fetch()) {
            return 'missing';
        }
        if ($this->empty_date_str($startedAt)) {
            return 'pending';
        } elseif ($this->empty_date_str($finishedAt)) {
            return 'processing';
        } else {
            return 'finished';
        }
    }

    private function empty_date_str($str)
    {
        return ! $str || ($str === '1970-01-01 01:00:01') || ($str === '1970-01-01 00:00:01');
    }
}
