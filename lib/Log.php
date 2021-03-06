<?php
class Log{
    protected $config,$cache,$mainIndex,$logFormat;
    protected $sum,$count,$distinct,$table,$where,$whereSign,$group;
    protected $stime,$etime,$period;
    
    public function __construct(array $config, $cache){
        $this->config = $config;
        $this->cache = $cache;
        $this->mainIndex = $cache->get('mainIndex');

        $logFormat = str_replace(array_keys($config['formatReg']),array_values($config['formatReg']),$config['logFormat']);
        $logFormat = "/".$logFormat."/i";
        $this->logFormat = $logFormat;
    }

    public function __set($name,$val){
        is_string($val) && $val = trim($val);
        if($val === ''){
            return;
        }
        switch($name){
            case 'period':
                //$period 小时
                $val *= 3600;
                break;
            case 'datetimerange':
                $this->stime = strtotime($val[0]);
                $this->etime = strtotime($val[1]);
                break;
            case 'stime':
            case 'etime':
                if(!is_numeric($val)){
                    $val = strtotime($val);
                }
                break;
            case 'where':
                $_val = $val;
                $val = [];
                $whereSign = [];
                foreach($_val as $field=>$value){
                    $pos = strpos($field,'#');
                    $sign = '';
                    if($pos){
                        $sign = substr($field,$pos+1);
                        $field = substr($field,0,$pos);
                    }else{
                        $sign = '=';
                    }
                    $val[$field] = $value;
                    $whereSign[$field] = $sign == '==' ? '=' : $sign;
                }
                $this->whereSign = $whereSign;
                break;
        }
        
        $this->$name = $val;
    }

    protected function getLogFiles($stime,$etime){
        $logFiles = [];
        foreach($this->mainIndex as $file=>$fileIndex){
            if($etime >= $fileIndex['stime'] && $stime <= $fileIndex['etime']){
                $logFiles[$file] = $fileIndex['tables'];
            }
        }
        return $logFiles;
    }
    
    protected function buildFields($log){
        preg_match($this->logFormat, $log, $match);
        if(!$match){
            return $fields;
        }
        array_shift($match);
        $fields = array_combine($this->config['logFormatAs'],$match);
        
        if(isset($this->config['url_query'])){
            $url_query = $this->config['url_query'];
            if($fields[$url_query]){
                parse_str($fields[$url_query],$arr);
                //unset($fields[$url_query]);
                foreach($arr as $k=>$v){
                    $fields[$url_query.'.'.$k] = $v;
                }
            }
            
        }
        return $fields;
    }

    protected function filterWhere(&$match){
        $where = $this->where;
        $whereSign = $this->whereSign;
        foreach($where as $field=>$value){
            $sign = isset($whereSign[$field]) ? $whereSign[$field] : '=';
            if(!isset($match[$field])){
                return false;
            }
            $_value = $match[$field];
            if($sign == '<' && $_value >= $value){
                return false;
            }elseif($sign == '<=' && $_value > $value){
                return false;
            }elseif($sign == '>' && $_value <= $value){
                return false;
            }elseif($sign == '>=' && $_value < $value){
                return false;
            }elseif($sign == '=' && $_value !== $value){
                return false;
            }elseif($sign == 'like'){
                extract(self::prepareLike($value));//$string,$start,$end
                $value = $string;
                $len = strlen($table);
                if(!self::matchLike($_value,$value,$start,$end,$len)){
                    return false;
                }
            }
        }
        return true;
    }
    
    /*
     * @indexFile 索引文件名
     * @tables 日志文件包含的所有table
     * @table 要查找的table
     */
    protected function getPos($indexFile,$tables,$table){
        if($table == '*'){
            $pos = unpack('I*',file_get_contents($indexFile));
            sort($pos);
            return $pos;
        }

        $pos = [];
        extract(self::prepareLike($table));//$string,$start,$end
        $table = $string;
        $len = strlen($table);

        $index = $this->cache->get($indexFile);
        if($index){
            foreach($tables as $_table=>$ipos){
                if(!self::matchLike($_table,$table,$start,$end,$len)){
                    continue;
                }
                $pos = array_merge($pos,$index[$_table]);
            }
        }else{
            $fp = fopen($indexFile,'r');
            foreach($tables as $_table=>$ipos){
                if(!self::matchLike($_table,$table,$start,$end,$len)){
                    continue;
                }
                fseek($fp,$ipos[0]);
                $_pos = unpack('I*',fread($fp,$ipos[1]-$ipos[0]));
                $pos = array_merge($pos,array_values($_pos));
            }
            fclose($fp);
        }
        sort($pos);
        return $pos;
    }
    
    protected static function prepareLike($string){
        $start = true; //前面严格匹配
        $end = true; //后面严格匹配
        $len = strlen($string);
        if($string[$len-1] == '%'){
            $end = false;
            $string = substr($string,0,$len-1);
        }
        if($string[0] == '%'){
            $start = false;
            $string = substr($string,1,strlen($string));
        }
        return compact('string','start','end');
    }
    
    protected static function matchLike($string,$str,$start,$end,$len){
        $i = strpos($string,$str);
        return !($i === false || ($start && $i!=0) || ($end && (strlen($string)-$i)!=$len));
    }
    
    protected function filterInput(array $input){
        isset($input['sum']) && $this->__set('sum', $input['sum']);
        isset($input['count']) && $input['count']!='false' && $this->__set('count', $input['count']);
        isset($input['distinct']) && $this->__set('distinct', $input['distinct']);
        isset($input['table']) && $this->__set('table', $input['table']);
        isset($input['period']) && $this->__set('period',$input['period']);
        isset($input['stime']) && $this->__set('stime', $input['stime']);
        isset($input['etime']) && $this->__set('etime', $input['etime']);
        isset($input['datetimerange']) && $this->__set('datetimerange', $input['datetimerange']);
        isset($input['group']) && $this->__set('group', $input['group']);
        if(isset($input['where_f']) && isset($input['where_v'])){
            $this->__set('where', array_combine($input['where_f'], $input['where_v']));
        }
    }

    protected function prepareQuery(){
        !$this->etime && $this->etime = time();
        !$this->stime && $this->stime = $this->etime - 3600;
        !$this->period && $this->period = 3600;
        !$this->where && $this->where = [];
        !$this->whereSign && $this->whereSign = [];
        !$this->table && $this->table = '*';
    }
    
    /*
     * 得到时间区间数组
     */
    protected function getPeriodArr($stime,$etime,$period){
        $periodArr = [];
        for($i=$stime+$period; $i<=$etime; $i+=$period){
            $periodArr[] = $i;
        }
        $periodArr[] = $i;
        return $periodArr;
    }

    protected function beforeGet(){
        if(!$this->group){
            $periodArr = $this->getPeriodArr($this->stime,$this->etime,$this->period);
            $dataArr = array_fill(0,count($periodArr),0);
            $this->periodArr = $periodArr;
        }else{
            $dataArr = [];
        }
        
        if($this->distinct){
            $this->lastDataKey = '';
        }
        $this->dataArr = $dataArr;
    }
    
    /* $fields, array combined $config['logFormatAs'] with one line of log
     */
    protected function getFields(array $fields){
        $group = $this->group;
        $stime = $this->stime;
        $period = $this->period;
        $time = $fields[$this->config['time']];
        if(!is_numeric($time)){
            $time = strtotime($time);
        }
        
        $key = $group ? $fields[$group] : intval(($time - $stime)/$period);
        if($this->distinct){
            $value = $fields[$this->distinct];
            if(!isset($this->dataArr[$key])){
                $this->dataArr[$key] = [];
            }
            if(!in_array($value,$this->dataArr[$key])){
                $this->dataArr[$key][] = $value;
            }
            return;
        }elseif($this->count){
            $value = 1;
        }elseif($this->sum){
            $value = $fields[$this->sum];
        }

        if(isset($this->dataArr[$key])){
            $this->dataArr[$key] += $value;
        }else{
            $this->dataArr[$key] = $value;
        }
    }
    
    public function get(array $input, swoole_server $serv){
        $this->filterInput($input);
        $this->prepareQuery();
        $this->beforeGet();
        $stime = $this->stime;
        $etime = $this->etime;
        $logFiles = $this->getLogFiles($stime,$etime);

        $tasks = [];
        foreach($logFiles as $file=>$tables){
            if(!is_file($file)){
                continue;
            }
            $tasks[] = [
                [$this,'mapLog'],
                [$file,$tables]
            ];
        }
        
        $res = $serv->taskWaitMulti($tasks,300);

        return $this->reduceLog($res);
    }

    protected function getReduceData(){
        return $this->dataArr;
    }
    
    public function mapLog($file, $tables){
        $table = $this->table;
        $stime = $this->stime;
        $etime = $this->etime;
        $timeAs = $this->config['time'];
        $posArr = $this->getPos($file.'.index',$tables,$table);
        $logFp = fopen($file,'r');

        foreach($posArr as $pos){
            fseek($logFp,$pos);
            $log = fgets($logFp);
            $fields = $this->buildFields($log);
            $time = $fields[$timeAs];
            if(!is_numeric($time)){
                $time = strtotime($time);
            }
            if($time > $etime || $time < $stime){
                continue;
            }
            if(!$this->filterWhere($fields)){
                continue;
            }
            $this->getFields($fields);
        }
        fclose($logFp);
        
        return $this->getReduceData();
    }
    
    protected function reduceLog(array $results){
        $isDistinct = $this->distinct;
        $yData = null;
        foreach($results as $result){
            if(!$yData){
                $yData = $result;
                continue;
            }
            foreach($result as $key=>$value){
                if(isset($yData[$key])){
                    $yData[$key] = $isDistinct ? array_merge($yData[$key],$value) : $yData[$key]+$value;
                }else{
                    $yData[$key] = $value;
                }
            }
        }
        if($isDistinct){
            array_map(function($v){
                return count(array_unique($v));
            },$yData);
        }
        
        $xData = $this->group ? array_keys($yData) : array_map(function($v){return date('Y-m-d H:i:s',$v);}, $this->periodArr);

        return [
            'xData' => $xData,
            'yData' => array_values($yData)
        ];
    }
    
    public function getHtml(){
        return file_get_contents($this->config['rootPath'].'/view/index.html');
    }
}