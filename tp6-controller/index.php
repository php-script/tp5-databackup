<?php
namespace app\controller;

use app\BaseController;
use tp5er\Backup;

class Index extends BaseController
{
    /**
     * @var Backup
     */
    protected $db;

    function initialize()
    {
        $this->db= new Backup();
        parent::initialize(); // TODO: Change the autogenerated stub
    }

    public function index()
    {

        $list= $this->db->dataList();
        var_dump($list);
    }

    function importlist(){
        $list= $this->db->fileList();
        var_dump($list);
    }

    function import(){
        $fileinfo  = $this->db->getFile();
        $this->db->Backup_Init();
        $start= $this->db->setFile($fileinfo["file"])->backup("user", 0);
        var_dump($start);
    }
}
