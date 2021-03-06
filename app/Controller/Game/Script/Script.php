<?php
namespace App\Controller\Game\Script;

use App\Controller\AbstractController;

/**
 *
 */
class Script extends AbstractController
{
    public $searchPaths;

    public function init()
    {
        $this->searchPaths = [
            config('settings_path') . '/Envir',
            config('settings_path') . '/Envir/NPCs',
        ];
    }

    public function removeBOM($str)
    {
        if (strlen($str) >= 3) {
            $c0 = ord($str[0]);
            $c1 = ord($str[1]);
            $c2 = ord($str[2]);

            if ($c0 == 0xFE && $c1 == 0xFF) {
                // -- UTF-16BE BOM文件头: [0xFE, 0xFF],
                $str = substr($str, 2);
            } else if ($c0 == 0xFF && $c1 == 0xFE) {
                // -- UTF-16LE BOM文件头: [0xFF, 0xFE],
                $str = substr($str, 2);
            } else if ($c0 == 0xEF && $c1 == 0xBB && $c2 == 0xBF) {
                // -- UTF-8 BOM文件头: [0xEF, 0xBB, 0xBF]
                $str = substr($str, 3);
            }
        }

        return $str;
    }

    public function skipLine($str)
    {
        if ($str == "" || $str == " ") {
            return true;
        }

        // 注释
        if ($str[0] == ';') {
            return true;
        }

        return false;
    }

    public function expandScript($lines)
    {
        $compiled = [];

        foreach ($lines as $line) {
            if ($this->skipLine($line)) {
                continue;
            }

            if ($line[0] == '#') {
                if (strpos($line, "#INSERT") === 0) {
                    preg_match('/\#INSERT\s*\[([^\n]+)\]\s*(@[^\n]+)/', $line, $match);

                    $insertLines = $this->readfile($match[1]);

                    if (!$insertLines) {
                        return false;
                    }

                    $insertLines = $this->expandScript($insertLines);

                    if (!$insertLines) {
                        return false;
                    }

                    foreach ($insertLines as $v) {
                        $compiled[] = $v;
                    }

                    continue;

                } elseif (strpos($line, "#INCLUDE") === 0) {
                    preg_match('/#INCLUDE\s*\[([^\n]+)\]\s*(@[^\n]+)/', $line, $match);

                    $insertLines = $this->loadScriptPage($this->fixSeparator($match[1]), strtoupper($match[2]));

                    if (!$insertLines) {
                        return false;
                    }

                    foreach ($insertLines as $v) {
                        $compiled[] = $v;
                    }
                    continue;
                }
            }

            $compiled[] = $line;
        }

        return $compiled;
    }

    public function add($curPage)
    {
        # code...
    }

    public function loadFile($file)
    {
        $lines = $this->readfile($file);
        if (!$lines) {
            return false;
        }

        return $this->load($lines);
    }

    public function load($lines)
    {
        $obj = $this->precompile($lines);

        // return $obj;

        if (!$obj) {
            return false;
        }

        $sc = [];

        if (!$this->parseGoods($sc, $this->take($obj, '[Trade]'))) {
            return false;
        }

        if (!$this->parseTypes($sc, $this->take($obj, '[Types]'))) {
            return false;
        }

        if (!$this->parseQuests($sc, $this->take($obj, '[Quests]'))) {
            return false;
        }

        $sc['Pages'] = [
            "Name"        => [],
            "CheckList"   => [],
            "ActList"     => [],
            "ElseActList" => [],
            "Say"         => [],
            "ElseSay"     => [],
        ];

        foreach ($obj['Pages'] as $key => $ps) {
            $page = [
                'Name'        => strtoupper($ps['Name']),
                "CheckList"   => [],
                "ActList"     => [],
                "ElseActList" => [],
                "Say"         => [],
                "ElseSay"     => [],
            ];

            if (!$this->parsePage($ps, $page)) {
                return false;
            }

            $sc['Pages'][strtoupper($page['Name'])] = $page;
        }

        return $sc;
    }

    public function precompile($lines)
    {
        $lines = $this->expandScript($lines);

        if (!$lines) {
            return false;
        }

        $ret = [];

        $curPage = [];

        foreach ($lines as $line) {
            if ($line[0] == '[') {
                preg_match('/^(\[[^\n]+\])\s*$/', $line, $match);

                if (count($match) > 0) {
                    if ($curPage) {
                        $ret['Pages'][strtoupper($curPage['Name'])] = $curPage;
                    }

                    $curPage = [
                        'Name'  => $match[1],
                        'Lines' => [],
                    ];

                    continue;
                }
            }

            if ($curPage) {
                $curPage['Lines'][] = $line;
            }
        }

        if ($curPage) {
            $ret['Pages'][strtoupper($curPage['Name'])] = $curPage;
        }

        return $ret;
    }

    public function take(&$obj, $name)
    {
        $name = strtoupper($name);

        $ps = false;

        if (!empty($obj['Pages'][$name])) {
            $ps = $obj['Pages'][$name];
            unset($obj['Pages'][$name]);
        }

        return $ps;
    }

    public function parseGoods(&$sc, $pages)
    {
        $sc['Goods'] = [];

        if (!$pages) {
            return true;
        }

        foreach ($pages['Lines'] as $key => $v) {
            $sc['Goods'][] = trim($v);
        }

        return true;
    }

    public function parseTypes(&$sc, $pages)
    {
        $sc['Types'] = $this->parseIntArray($sc, $pages);
        return true;
    }

    public function parseQuests(&$sc, $pages)
    {
        $sc['Quests'] = $this->parseIntArray($sc, $pages);
        return true;
    }

    public function parseIntArray($sc, $pages)
    {
        if (!$pages) {
            return true;
        }

        $ret = [];

        foreach ($pages['Lines'] as $key => $v) {
            $ret[] = intval($v);
        }

        return $ret;
    }

    public function parsePage($ps, &$page)
    {
        $info = [
            'IF'      => [],
            'SAY'     => [],
            'ACT'     => [],
            'ELSEACT' => [],
            'ELSESAY' => [],
        ];
        $currentCmd = '';

        foreach ($ps['Lines'] as $k => $line) {
            // $line = trim($v);

            if (!$line || $line == '' || $line[0] == ";") {
                continue;
            }

            if ($line[0] == '#') {
                preg_match('/#(\w+)/', $line, $match);

                switch (strtoupper($match[1])) {
                    case "IF":
                        $currentCmd = 'IF';
                        break;

                    case "SAY":
                        $currentCmd = 'SAY';
                        break;

                    case "ACT":
                        $currentCmd = 'ACT';
                        break;

                    case "ELSEACT":
                        $currentCmd = 'ELSEACT';
                        break;

                    case "ELSESAY":
                        $currentCmd = 'ELSESAY';
                        break;

                    default:
                        EchoLog(sprintf('错误 :%s', $ps['Name'] . '---' . $match[1]), 'e');
                        return false;
                }

                continue;
            }

            if ($line[0] == '[' && $line[strlen($line) - 1] == ']') {
                break;
            }

            $info[$currentCmd][] = rtrim($line);
        }

        $page['Say']     = !empty($info['SAY']) ? $info['SAY'] : [];
        $page['ElseSay'] = !empty($info['ELSESAY']) ? $info['ELSESAY'] : [];

        $page['CheckList'] = $this->parseActions($this->Context->defaultContext['Checks'], $info['IF']);
        if ($page['CheckList'] === false) {
            return true;
        }

        $page['ActList'] = $this->parseActions($this->Context->defaultContext['Actions'], $info['ACT']);
        if ($page['ActList'] === false) {
            return true;
        }

        $page['ElseActList'] = $this->parseActions($this->Context->defaultContext['Actions'], $info['ELSEACT']);
        if ($page['ElseActList'] === false) {
            return true;
        }

        return true;
    }

    public function parseActions($mp, $lst)
    {
        $actions = [];

        foreach ($lst as $it) {
            $ck = $this->parseAction($mp, $it);

            if (!$ck) {
                return false;
            }

            $actions[] = $ck;
        }

        return $actions;
    }

    public function parseAction($mp, $str)
    {
        $parts = explode(' ', $str);

        $funName = strtoupper($parts[0]);

        $method = !empty($mp[$funName]) ? $mp[$funName] : false;

        if (!$method) {
            EchoLog(sprintf('没有这个方法: [%s]', $funName), 'w');
            return false;
        }

        $inst           = [];
        $inst['Args']   = array_slice($parts, 1);
        $inst['Func']   = $method['Func'];
        $inst['Skiped'] = true;

        return $inst;
    }

    public function readfile($file)
    {
        if (!$fp = fopen($this->fullpath($file), 'r')) {
            EchoLog(sprintf('打开文件失败 :%s', $file), 'e');
            return false;
        }

        $lines = [];
        while (!feof($fp)) {
            // $lines[] = trim($this->removeBOM(stream_get_line($fp, 2048, "\n")));
            $lines[] = rtrim($this->removeBOM(stream_get_line($fp, 2048, "\n")));
        }
        fclose($fp);

        return $lines;
    }

    public function fullpath($file)
    {
        $this->init();
        $file = $this->fixSeparator($file);
        foreach ($this->searchPaths as $path) {
            $file_exists = $path . '/' . $file;
            if (file_exists($file_exists)) {
                break;
            }
        }
        return $file_exists;
    }

    public function fixSeparator($str)
    {
        return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $str);
    }

    public function loadScriptPage($file, $page)
    {
        $lines = $this->readfile($file);

        if (!$lines) {
            return false;
        }

        $page = '[' . $page . ']';

        $stat = 0;

        $ret = [];
        foreach ($lines as $line) {
            if ($this->skipLine($line)) {
                continue;
            }

            switch ($stat) {
                case 0:
                    if ($line[0] == '[' && strpos(strtoupper($line), $page) === 0) {
                        $stat = 1;
                    }
                    break;

                case 1:
                    if ($line[0] == '{') {
                        $stat = 2;
                    }
                    break;

                case 2:
                    if ($line[0] == '}') {
                        return $ret;
                    }

                    $ret[] = $line;
                    break;
            }
        }

        EchoLog(sprintf('语法错误： %s; ', $this->fullpath($file)), 'e');
        return false;
    }

    public function call($page, $npc, $p)
    {
        $page = strtoupper($page);

        $ps = $npc['Script']['Pages'][$page] ?? null;

        if (!$ps) {
            EchoLog(sprintf('没有页面： %s; ', $page), 'w');
            return false;
        }

        $acts = [];
        $say  = [];

        if ($this->check($ps, $npc, $p)) {
            $acts = $ps['ActList'];
            $say  = $ps['Say'];
        } else {
            $acts = $ps['ElseActList'];
            $say  = $ps['ElseSay'];
        }

        if ($acts) {
            foreach ($acts as $act) {
                $shouldBreak = false;

                switch ($act['Func']) {
                    case 'BREAK':
                        $shouldBreak = true;
                        break;
                    
                    case 'GOTO':
                        return $this->call('['.$act['Args'][0].']',$npc,$p);
                        break;
                }

                if($shouldBreak)
                {
                    break;
                }
            }
        }

        return $say;
    }

    public function check($ps, $npc, $p)
    {
        if (empty($ps['CheckList']) || count($ps['CheckList']) == 0) {
            return true;
        }

        $flag = false;

        foreach ($ps['CheckList'] as $value) {
            $func = $value['Func'];
            if($this->NpcScript->$func($value['Args'], $p))
            {
                $flag = true;
            };
        }

        return $flag;
    }
}
