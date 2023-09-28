<?php
// +----------------------------------------------------------------------
// | Author: tp5er <tp5er@qq.com>
// | QQ: 1751212020
// +----------------------------------------------------------------------
namespace tp5er;

use think\Db;
use think\App;

class Backup
{
    /**
     * 文件指针
     * @var resource
     */
    private $fp;
    /**
     * 备份文件信息 part - 卷号，name - 文件名
     * @var array
     */
    private $file;
    /**
     * 当前打开文件大小
     * @var integer
     */
    private $size = 0;

    /**
     * 数据库配置
     * @var integer
     */
    private $dbconfig = array();
    /**
     * 备份配置
     * @var integer
     */
    private $config = array(
        'path'     => '',
        //数据库备份路径
        'part'     => 20971520,
        //数据库备份卷大小
        'compress' => 0,
        //数据库备份文件是否启用压缩 0不压缩 1 压缩
        'level'    => 9,
    );

    /**
     * 数据库备份构造方法
     * @param array $config 备份配置信息
     * @throws \Exception
     */
    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
        if (empty($this->config["path"])){
            $this->config["path"]=  \app()->getRootPath()."backup/";
        }
        //初始化文件名
        $this->setFile();
        //初始化数据库连接参数
        $this->setDbConn();
        //检查文件是否可写
        if (!$this->checkPath($this->config['path'])) {
            throw new \Exception("The current directory is not writable");
        }
    }

    /**
     * 设置脚本运行超时时间
     * 0表示不限制，支持连贯操作
     */
    public function setTimeout($time = null)
    {
        if (!is_null($time)) {
            set_time_limit($time) || ini_set("max_execution_time", $time);
        }
        return $this;
    }

    /**
     * 设置数据库连接必备参数
     * @param array $dbconfig 数据库连接配置信息
     * @return object
     */
    public function setDbConn($dbconfig = [])
    {
        if (empty($dbconfig)) {
            $this->dbconfig = config('database');
            //$this->dbconfig = Config::get('database');
        } else {
            $this->dbconfig = $dbconfig;
        }
        return $this;
    }

    /**
     * 设置备份文件名
     * @param Array $file 文件名字
     * @return $this
     */
    public function setFile($file = null)
    {
        if (is_null($file)) {
            $this->file = ['name' => date('Ymd-His'), 'part' => 1];
        } else {
            if (!array_key_exists("name", $file) && !array_key_exists("part", $file)) {
                $this->file = $file['1'];
            } else {
                $this->file = $file;
            }
        }
        return $this;
    }

    /**
     * 数据类连接
     * @return \think\db\ConnectionInterface
     */
    public static function connect()
    {
        if (APP::VERSION >= "6.0.0") {
            return \think\facade\Db::connect();
        } else {
            return Db::connect();
        }
    }
    /**
     * 数据库表列表
     * @param $table
     * @param $type
     * @return array
     */
    public function dataList($table = null, $type = 1)
    {
        $db = self::connect();
        if (is_null($table)) {
            $list = $db->query("SHOW TABLE STATUS");
        } else {
            if ($type) {
                $list = $db->query("SHOW FULL COLUMNS FROM {$table}");
            } else {
                $list = $db->query("show columns from {$table}");
            }
        }
        return array_map('array_change_key_case', $list);
        //$list;
    }

    /**
     * 数据库备份文件列表
     * @return array
     */
    public function fileList()
    {
        if (!is_dir($this->config['path'])) {
            mkdir($this->config['path'], 0755, true);
        }
        $path = realpath($this->config['path']);
        $flag = \FilesystemIterator::KEY_AS_FILENAME;
        $glob = new \FilesystemIterator($path, $flag);
        $list = array();
        foreach ($glob as $name => $file) {
            if (preg_match('/^\\d{8,8}-\\d{6,6}-\\d+\\.sql(?:\\.gz)?$/', $name)) {
                $name1 = $name;
                $name  = sscanf($name, '%4s%2s%2s-%2s%2s%2s-%d');
                $date  = "{$name[0]}-{$name[1]}-{$name[2]}";
                $time  = "{$name[3]}:{$name[4]}:{$name[5]}";
                $part  = $name[6];
                if (isset($list["{$date} {$time}"])) {
                    $info         = $list["{$date} {$time}"];
                    $info['part'] = max($info['part'], $part);
                    $info['size'] = $info['size'] + $file->getSize();
                } else {
                    $info['part'] = $part;
                    $info['size'] = $file->getSize();
                }
                $extension               = strtoupper(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                $info['name']            = $name1;
                $info['compress']        = $extension === 'SQL' ? '-' : $extension;
                $info['time']            = strtotime("{$date} {$time}");
                $list["{$date} {$time}"] = $info;
            }
        }
        return $list;
    }

    /**
     * @param $type
     * @param $time
     * @return array|false|mixed|string
     * @throws \Exception
     */
    public function getFile($type = '', $time = 0)
    {
        if (!is_numeric($time)) {
            throw new \Exception("{$time} Illegal data type");
        }
        switch ($type) {
            case 'time':
                $name = date('Ymd-His', $time) . '-*.sql*';
                $path = realpath($this->config['path']) . DIRECTORY_SEPARATOR . $name;
                return glob($path);
            case 'timeverif':
                $name  = date('Ymd-His', $time) . '-*.sql*';
                $path  = realpath($this->config['path']) . DIRECTORY_SEPARATOR . $name;
                $files = glob($path);
                $list  = array();
                foreach ($files as $name) {
                    $basename        = basename($name);
                    $match           = sscanf($basename, '%4s%2s%2s-%2s%2s%2s-%d');
                    $gz              = preg_match('/^\\d{8,8}-\\d{6,6}-\\d+\\.sql.gz$/', $basename);
                    $list[$match[6]] = array($match[6], $name, $gz);
                }
                $last = end($list);
                if (count($list) === $last[0]) {
                    return $list;
                } else {
                    throw new \Exception("File {$files['0']} may be damaged, please check again");
                }
            case 'pathname':
                return "{$this->config['path']}{$this->file['name']}-{$this->file['part']}.sql";
            case 'filename':
                return "{$this->file['name']}-{$this->file['part']}.sql";
            case 'filepath':
                return $this->config['path'];
            default:
                return array('pathname' => "{$this->config['path']}{$this->file['name']}-{$this->file['part']}.sql", 'filename' => "{$this->file['name']}-{$this->file['part']}.sql", 'filepath' => $this->config['path'], 'file' => $this->file);;
        }
    }

    /**
     * 删除备份文件
     * @param $time
     * @return mixed
     * @throws \Exception
     */
    public function delFile($time)
    {
        if ($time) {
            $file = $this->getFile('time', $time);
            array_map("unlink", $this->getFile('time', $time));
            if (count($this->getFile('time', $time))) {
                throw new \Exception("File {$file} deleted failed");
            } else {
                return $time;
            }
        } else {
            throw new \Exception("{$time} Time parameter is incorrect");
        }
    }

    /**
     * 下载备份
     * @Author: 浪哥 <939881475@qq.com>
     * @param string $time
     * @param integer $part
     * @return array|mixed|string
     * @throws \Exception
     */
    public function downloadFile($time, $part = 0)
    {
        $file     = $this->getFile('time', $time);
        $fileName = $file[$part];
        if (file_exists($fileName)) {
            ob_end_clean();
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($fileName));
            header('Content-Disposition: attachment; filename=' . basename($fileName));
            readfile($fileName);
        } else {
            throw new \Exception("{$time} File is abnormal");
        }
    }

    /**
     * @param $start
     * @param $time
     * @return array|false|int
     * @throws \Exception
     */
    public function import($start, $time)
    {
        //还原数据
        $db         = self::connect();
        $this->file = $this->getFile('time', $time);
        if ($this->config['compress']) {
            $gz   = gzopen($this->file[0], 'r');
            $size = 0;
        } else {
            $size = filesize($this->file[0]);
            $gz   = fopen($this->file[0], 'r');
        }
        $sql = '';
        if ($start) {
            $this->config['compress'] ? gzseek($gz, $start) : fseek($gz, $start);
        }
        for ($i = 0; $i < 1000; $i++) {
            $sql .= $this->config['compress'] ? gzgets($gz) : fgets($gz);
            if (preg_match('/.*;$/', trim($sql))) {
                if (false !== $db->execute($sql)) {
                    $start += strlen($sql);
                } else {
                    return false;
                }
                $sql = '';
            } elseif ($this->config['compress'] ? gzeof($gz) : feof($gz)) {
                return 0;
            }
        }
        return array($start, $size);
    }

    /**
     * 写入初始数据
     * @return boolean true - 写入成功，false - 写入失败
     */
    public function Backup_Init()
    {

        $hostname = $this->dbconfig["hostname"] ?? $this->dbconfig["connections"][$this->dbconfig["default"]]["hostname"];
        $hostport = $this->dbconfig["hostport"] ?? $this->dbconfig["connections"][$this->dbconfig["default"]]["hostport"];
        $database = $this->dbconfig["database"] ?? $this->dbconfig["connections"][$this->dbconfig["default"]]["database"];

        $sql = "-- -----------------------------\n";
        $sql .= "-- Think MySQL Data Transfer \n";
        $sql .= "-- \n";
        $sql .= "-- Host     : " . $hostname . "\n";
        $sql .= "-- Port     : " . $hostport . "\n";
        $sql .= "-- Database : " . $database . "\n";
        $sql .= "-- \n";
        $sql .= "-- Part : #{$this->file['part']}\n";
        $sql .= "-- Date : " . date("Y-m-d H:i:s") . "\n";
        $sql .= "-- -----------------------------\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        return $this->write($sql);
    }

    /**
     * 备份表结构
     * @param string $table 表名
     * @param integer $start 起始行数
     * @return boolean        false - 备份失败
     */
    public function backup($table, $start)
    {
        $db = self::connect();
        // 备份表结构
        if (0 == $start) {
            $result = $db->query("SHOW CREATE TABLE `{$table}`");
            $sql    = "\n";
            $sql    .= "-- -----------------------------\n";
            $sql    .= "-- Table structure for `{$table}`\n";
            $sql    .= "-- -----------------------------\n";
            $sql    .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql    .= trim($result[0]['Create Table'] ?? $result[0]['Create View']) . ";\n\n";
            if (false === $this->write($sql)) {
                return false;
            }
        }
        //数据总数
        $result = $db->query("SELECT COUNT(*) AS count FROM `{$table}`");
        $count  = $result['0']['count'];
        //备份表数据
        if ($count) {
            //写入数据注释
            if (0 == $start) {
                $sql = "-- -----------------------------\n";
                $sql .= "-- Records of `{$table}`\n";
                $sql .= "-- -----------------------------\n";
                $this->write($sql);
            }
            //备份数据记录
            $result = $db->query("SELECT * FROM `{$table}` LIMIT {$start}, 1000");
            foreach ($result as $row) {
                $values = [];
                foreach ($row as $val) {
                    if (is_numeric($val)) {
                        $values[] = $val;
                    } else if (is_null($val)) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . str_replace(array("\r", "\n"), array('\\r', '\\n'), addslashes($val)) . "'";
                    }
                }
                $sql = "INSERT INTO `{$table}` VALUES (" . implode(", ", $values) . ");\n";
                if (false === $this->write($sql)) {
                    return false;
                }
            }
            //还有更多数据
            if ($count > $start + 1000) {
                //return array($start + 1000, $count);
                return $this->backup($table, $start + 1000);
            }
        }
        //备份下一表
        return 0;
    }

    /**
     * 优化表
     * @param String $tables 表名
     * @return String $tables
     * @throws \Exception
     */
    public function optimize($tables = null)
    {
        if ($tables) {
            $db = self::connect();
            if (is_array($tables)) {
                $tables = implode('`,`', $tables);
                $list   = $db->query("OPTIMIZE TABLE `{$tables}`");
            } else {
                $list = $db->query("OPTIMIZE TABLE `{$tables}`");
            }
            if ($list) {
                return $tables;
            } else {
                throw new \Exception("data sheet'{$tables}'Repair mistakes please try again!");
            }
        } else {
            throw new \Exception("Please specify the table to be repaired!");
        }
    }

    /**
     * 修复表
     * @param String $tables 表名
     * @return String $tables
     * @throws \Exception
     */
    public function repair($tables = null)
    {
        if ($tables) {
            $db = self::connect();
            if (is_array($tables)) {
                $tables = implode('`,`', $tables);
                $list   = $db->query("REPAIR TABLE `{$tables}`");
            } else {
                $list = $db->query("REPAIR TABLE `{$tables}`");
            }
            if ($list) {
                return $list;
            } else {
                throw new \Exception("data sheet'{$tables}'Repair mistakes please try again!");
            }
        } else {
            throw new \Exception("Please specify the table to be repaired!");
        }
    }

    /**
     * 写入SQL语句
     * @param string $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     */
    private function write($sql)
    {
        $size = strlen($sql);
        //由于压缩原因，无法计算出压缩后的长度，这里假设压缩率为50%，
        //一般情况压缩率都会高于50%；
        $size = $this->config['compress'] ? $size / 2 : $size;
        $this->open($size);
        return $this->config['compress'] ? @gzwrite($this->fp, $sql) : @fwrite($this->fp, $sql);
    }

    /**
     * 打开一个卷，用于写入数据
     * @param integer $size 写入数据的大小
     */
    private function open($size)
    {
        if ($this->fp) {
            $this->size += $size;
            if ($this->size > $this->config['part']) {
                $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
                $this->fp = null;
                $this->file['part']++;
                session('backup_file', $this->file);
                $this->Backup_Init();
            }
        } else {
            $backuppath = $this->config['path'];
            $filename   = "{$backuppath}{$this->file['name']}-{$this->file['part']}.sql";
            if ($this->config['compress']) {
                $filename = "{$filename}.gz";
                $this->fp = @gzopen($filename, "a{$this->config['level']}");
            } else {
                $this->fp = @fopen($filename, 'a');
            }
            $this->size = filesize($filename) + $size;
        }
    }

    /**
     * 检查目录是否可写
     * @param string $path 目录
     * @return boolean
     */
    protected function checkPath($path)
    {
        if (is_dir($path)) {
            return true;
        }
        if (mkdir($path, 0755, true)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 析构方法，用于关闭文件资源
     */
    public function __destruct()
    {
        if ($this->fp) {
            $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
        }
    }
}
