<?php
/**
 * 将ecos架构中的dbschema更新逻辑扒出来了,一会可以用到其他结构中
 * by ivan
 */

define('ROOT_DIR', realpath(dirname(__FILE__)));

function updatedb(){
    $dir = ROOT_DIR.'/testdb';//数据表所在目录
    if (!$handle = @opendir($dir)) {     //检测要打开目录是否存在
        die("没有该目录");
    }
    $diff = array();
    while (false !== ($file = readdir($handle))) {
        if ($file !== "." && $file !== "..") {       //排除当前目录与父级目录
            $filepath = $dir . DIRECTORY_SEPARATOR . $file;
            $file = explode('.',$file);
            $odb = new operate_db($file[0],$filepath);
            $item_sql_arr = $odb->diff_sql();
            echo "<pre>";
            print_r($item_sql_arr);
            if(is_array($item_sql_arr)){
                $diff = array_merge($diff, $item_sql_arr);
            }
        }
    }

    if($diff){
        foreach($diff as $sql){
            $odb->dbh->exec($sql);
        }
    }
    echo "<pre>";
    print_r('ok');
    exit;
}

updatedb();
class operate_db{

    public $table_engine = 'InnoDB';
    public $table_prefix = 'yw';
    public $table_name;
    public $dbh;
    public $_define=[];

    /**
     * operate_db constructor.
     * @param $table 不带表前缀的表名
     * @param $file  表表对应的物理文件 全路径
     */
    function __construct($table,$filepath)
    {
        $this->table_name = $table;
        $this->db_file = $filepath;
        if(!$this->dbh){
            $this->cennect_db();
        }
    }

    function cennect_db(){
        $this->dbh = new PDO('mysql:host=127.0.0.1;dbname=test_db', 'root', 'root');
    }

    function real_table_name(){
        if($this->table_prefix){
            return $this->table_prefix.'_'.$this->table_name;
        }else{
            return $this->table_name;
        }
    }

    function diff_sql($be_careful=true){

        $diff = array();
        $real_table_name = $this->real_table_name();
        $old_define = $this->get_current_define($real_table_name);
        $define_lastmodified = filemtime($this->db_file);
        if($old_define){
            /*if($define_lastmodified<=$updatetime[$this->db_file]){
                return '';
            }*/


            //用新db文件创建一个临时表,和老表进行比对,然后删除临时表
            $tmp_table = 'tmp_'.uniqid();
            $re = $this->dbh->exec($this->get_sql($tmp_table));
            if($re!==0){
                die('文件结构有问题:'.$this->db_file);
            }
            $new_define = $this->get_current_define($tmp_table);
            $this->dbh->exec('drop table if exists '.$tmp_table);

            if($new_define==$old_define){
                return array();
            }else{
                $tb_define = $this->load();
                foreach($new_define['columns'] as $key=>$define){
                    if(isset($old_define['columns'][$key])){
                        if($old_define['columns'][$key] != $new_define['columns'][$key]){
                            if(!$old_define['columns'][$key]['required'] && $new_define['columns'][$key]['required']){
                                $default=$new_define['default']?$new_define['default']:"''";
                                $diff[] = "update {$real_table_name} set `{$key}`={$default} where `{$key}`=null;\n";
                            }
                            $alter[]='MODIFY COLUMN `'.$key.'` '.$this->get_column_define($tb_define['columns'][$key]);
                        }
                    }else{
                        $alter[]='ADD COLUMN `'.$key.'` '.$this->get_column_define($tb_define['columns'][$key]).' '.($last?('AFTER '.$last):'FIRST');
                    }
                    unset($old_define['columns'][$key]);
                    $last = $key;
                }

                if(is_array($old_define['columns'])){
                    if($be_careful){
                        foreach($old_define['columns'] as $c=>$def){
                            $alter[]='DROP COLUMN `'.$c.'`'; //设置默认值或者允许空值
                        }
                    }
                }

                if($alter){
                    $diff[]='ALTER IGNORE TABLE `'.$real_table_name."` \n\t".implode(",\n\t",$alter).';';
                }

                //todo: 索引和主键

                $old_define_index = $old_define['index'];

                foreach($new_define['index'] as $key=>$define){
                    if(isset($old_define['index'][$key])){
                        if($old_define['index'][$key] != $new_define['index'][$key]){
                            $diff[] = 'ALTER IGNORE TABLE `'.$real_table_name.'` DROP PRIMARY KEY, ADD '.$this->get_index_sql($key);
                        }
                        unset($old_define_index[$key]);
                    }else{
                        $diff[] = 'ALTER IGNORE TABLE `'.$real_table_name.'` ADD '.$this->get_index_sql($key);
                    }
                }

                if(is_array($old_define_index)){
                    foreach($old_define_index AS $key=>$define){
                        if($key === 'PRIMARY'){
                            $diff[] = 'ALTER IGNORE TABLE `'.$real_table_name.'` DROP PRIMARY KEY';
                        }else{
                            $diff[] = 'ALTER IGNORE TABLE `'.$real_table_name.'` DROP KEY `' . $key . '`';
                        }
                    }
                }
            }
        }else{
            $diff[]= $this->get_sql();
        }
        //此处存储文件修改时间,用于判断下次是否需要更新
        if($diff){
            //$updatetime[$this->db_file] = $define_lastmodified;
        }
        return $diff;
    }

    //新建表SQL组装
    function get_sql($tablename=null){
        if(!$tablename){
            $tablename = $this->real_table_name();
        }

        $define = $this->load();

        $rows = array();
        foreach($define['columns'] as $k=>$v){
            $rows[] = '`'.$k.'` '.$this->get_column_define($v);
        }

        if($define['pkeys']){
            $rows[] = $this->get_index_sql('PRIMARY',$define);
        }
        if(is_array($define['index'])){
            foreach($define['index'] as $key=>$value){
                $rows[] = $this->get_index_sql($key,$define);
            }
        }

        $sql = 'CREATE TABLE `'.$tablename."` (\n\t".implode(",\n\t",$rows)."\n)";
        $engine = isset($define['engine'])?$define['engine']:$this->table_engine;

        $sql.= 'ENGINE = '.$engine.' DEFAULT CHARACTER SET utf8';

        return $sql;
    }
    //索引
    function get_index_sql($name,$define){
        foreach ($define['pkeys'] as $k => $pkey) {
            $define['pkeys'][$k] = '`'.$pkey.'`';
        }
        if($name=='PRIMARY'){
            if($define['pkeys']){
                return 'primary key ('.implode(',',$define['pkeys']).')';
            }
        }else{
            $value = $define['index'][$name];
            return $value['prefix'].' INDEX '.$name.($value['type']?(' USING '.$value['type']):'').'(`'
                   .implode('`,`',$value['columns']).'`)';
        }
    }

    function get_column_define($v){
        $r = $v['realtype'];
        if(isset($v['required']) && $v['required']){
            $r.=' not null';
        }
        if(isset($v['default'])){
            if($v['default']===null){
                $r.=' default null';
            }elseif(is_string($v['default'])){
                $r.=' default \''.$v['default'].'\'';
            }else{
                $r.=' default '.$v['default'];
            }
        }

        if(isset($v['comment'])){
            $r .= ' comment \'' . $v['comment'] . '\'';
        }

        return $r;
    }

    //读取文件,并处理数据
    function &load(){

        if($this->_define[$this->table_name]){
            return $this->_define[$this->table_name];
        }

        require($this->db_file);

        $define = &$db[$this->table_name];
        $this->_define[$this->table_name] = &$define;

        foreach($define['columns'] as $k=>$v){
            $define['columns'][$k] = $this->_prepare_column($k, $v);
            if(isset($v['pkey']) && $v['pkey']){
                $define['pkeys'][$k] = $k;
            }
        }
        return $define;
    }

    function _prepare_column($col_name, $col_set){
        $col_set['realtype'] = $col_set['type'];
        if(is_array($col_set['type'])){
            $col_set['realtype'] = 'enum(\''.implode('\',\'',array_keys($col_set['type'])).'\')';
        }
        return $col_set;
    }

    //获取原表字段和主键
    private function get_current_define($tbname){

        $define = $this->dbh->query("show tables like '".$tbname."'");
        $has=$define->fetchAll();

        if($has){
            $rows = $this->dbh->query('SHOW FULL COLUMNS FROM '.$tbname);
            $columns = array();
            if($rows){
                foreach($rows as $c){
                    $columns[$c['Field']] = array(
                        'type'=>$c['Type'],
                        'default'=>$c['Default'],
                        'comment'=>$c['Comment'],
                        'required'=>!($c['Null']=='YES'),
                    );
                }
            }

            $rows = $this->dbh->query('SHOW INDEX FROM '.$tbname);
            $index = array();
            if($rows){
                foreach($rows as $row){
                    $index[$row['Key_name']] = array(
                        'Column_name'=>$row['Column_name'],
                        'Non_unique'=>$row['Non_unique'],
                        'Collation'=>$row['Collation'],
                        'Sub_part'=>$row['Sub_part'],
                        'Index_type'=>$row['Index_type'],
                    );
                }
            }

            return array('columns'=>$columns, 'index'=>$index);
        }else{
            return false;
        }
    }



}