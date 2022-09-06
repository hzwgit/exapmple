<?php

namespace Admin\Controller;

use Think\Model;

//资料管理
class DatamanagerController extends AdminController {

    //权限 0老师 1超管或指定管理员 2区域主管
    private $perssion;
    private $ossConfig;

    public function __construct() {
        parent::__construct();
        //判断是否绑定老师或区域管理员，超管直接通过
        if (!is_administrator()) {
            $archivesConfig = C('ARCHIVES');
            if (false == $staff_id = \session('staff_id')) {
                if (false == $staff_id = D('Member')->where(['uid' => is_login()])->getField('staff_id')) {
                    $this->redirect(U('Public/bindstaff'));
                    return;
                } else {
                    session('staff_id', $staff_id);
                    session('xgjid', M('Staff', '', 'DB_SHUHUASYSTEM')->where(['id' => session('staff_id')])->getField('xgjid'));
                }
            }
            $this->assign('staff_id', $staff_id);
            if (false == $this->perssion = session('perssion')) {
                if (in_array(UID, $archivesConfig['ADMIN'])) {
                    $this->perssion = 1;
                } else {
                    $limitType = M('ArchivesLimit')->order('limit_type desc')->where(['staff_id' => $staff_id])->getField('limit_type');
                    $this->perssion = $limitType == 2 ? 2 : 0;
                }
                session('perssion', $this->perssion);
            }
        } else {
            $this->perssion = 1;
        }
        $this->ossConfig = C('OSS_CONFIG_ARCHIVE');
        $this->assign('ossConfig', $this->ossConfig);
        $this->assign('perssion', $this->perssion);
        $this->assign('islist', cookie('listshowpay'));
    }

    public function home() {
        $this->display();
    }

    //校区列表
    public function index() {
        $map = [
            'level' => 1,
            'status' => 1
        ];
        $pca = I('pca', '');
        $result = [];
        //老师只能查看指定校区
        if ($this->perssion == 0) {
            //请求接口获取可操作校区id
            $campusData = json_decode(curlGet(C('OPWEB_SITE_HOST') . '/moniter/xiaoguanjia/xgjGetUserCampuslist?employee_id=' . session('xgjid')), true);
            $campusIds = [];
            foreach ($campusData as $vo) {
                $campusIds[] = $vo['id'];
            }
            if (!$campusIds) {
                write_log('未获取到校区url:' . C('OPWEB_SITE_HOST') . '/moniter/xiaoguanjia/xgjGetUserCampuslist?employee_id=' . session('xgjid'), 'datamanager');
                $this->assign('ShowErrors', '未获取到校区数据!');
                $map = '1<>1';
            } else {
                $map['campus_id'] = ['in', $campusIds];
            }
        }
        //读取level为1的数据
        $list = M('Archives')->where($map)->order('id desc')->group('title')->select();
//        echo M('Archives')->getLastSql();
        $this->assign('list', $list);
        $dirtree = $this->getFolddir();
        $this->assign('dirtree', $dirtree);
        $this->assign('now_level', 1);
        $this->assign('pic', 0);
        $this->display('details');
    }

    //二层以上的详情
    public function subordinateunit() {

        $pid = I('get.pid', 0);
        if ($pid == 0)
            $this->redirect('index');
        $map = [
            'parent_id' => $pid,
            'status' => 1,
        ];
        //父级信息
        $info = M('Archives')->where(['id' => $pid])->field('path,level,parent_id')->find();
        $info['dirhref'] = '<div class="backbut" data-href="' . U('subordinateunit', ['pid' => $archInfo['id']]) . '">..</div>>' . $this->getDirLink($pid);
        $this->assign('pid', $pid);
        $this->assign('info', $info);
        $this->assign('now_level', $info['level'] + 1);
        if ($info['level'] > 1) {
            //片区主管或老师
            if ($this->perssion != 1) {
                //查看指定老师
                if ($this->perssion == 2) {
                    $staffIds = M('ArchivesLimit')->where(['uid' => is_login(), 'type' => 2])->getField('ids');
                    $uids = M('Member')->where(['staff_id' => ['in', explode(',', trim($staffIds, ','))]])->getField('uid', true);
                    $uids[] = UID;
                    $map['uid'] = ['in', $uids];
                }
                if ($this->perssion == 0) {
                    $map['uid'] = UID;
                }
            }
        }
        $order = 'id asc';
        if ($info['level'] != 1) {
            $order = 'type asc,id desc';
        }
        $list = M('Archives')->where($map)->order()->select();
        $this->assign('list', $list);
        //拼接树目录
        $this->assign('uploadurl', U('documentsupdownuser', ['pid' => $pid]));
        $dirtree = $this->getFolddir();
        $this->assign('dirtree', $dirtree);
        $this->display('details');
        return;
//        }

        $this->assign('list', $list);
        $this->display();
    }

    private function getDirLink($id, &$linkStr = '') {
        $archInfo = M('Archives')->where(['id' => $id])->field('id,parent_id,alias_title,level')->find();
        $linkStr = '<div class="backbut" data-href="' . U('subordinateunit', ['pid' => $archInfo['id']]) . '">' . $archInfo['alias_title'] . '</div>>' . $linkStr;
        if ($archInfo['parent_id'] != 0) {
            $this->getDirLink($archInfo['parent_id'], $linkStr);
        }
        return $linkStr;
    }

    public function getFolddir($searval = '', $page = 1, $limit = 10) {
        $map['type'] = 0;
        $map['status'] = 1;
        $map['level'] = ['eq', 3];
        $where['type'] = 0;
        $where['status'] = 1;
        $where['level'] = ['gt', 3];
        if ($searval) {
            $map['path'] = ['like', '%' . trim($searval) . '%'];
            $where['path'] = ['like', '%' . trim($searval) . '%'];
        }
        //片区主管或老师
        if ($this->perssion != 1) {
            $where['uid'] = is_login();
            //查看指定校区和自身
            $campusIds = M('ArchivesLimit')->where(['uid' => is_login(), 'type' => 1])->getField('ids');
            if ($this->perssion == 2) {
                //管辖老师
                $staffIds = M('ArchivesLimit')->where(['uid' => is_login(), 'type' => 2])->getField('ids');
                $uids = M('Member')->where(['staff_id' => ['in', explode(',', trim($staffIds, ','))]])->getField('uid', true);
                $uids[] = is_login();
                $where['uid'] = ['in', $uids];
            }
            $map['campus_id'] = ['in', $campusIds];
            $where['campus_id'] = ['in', $campusIds];
        }
        $newMap = [
            $map,
            $where,
        ];
        $newMap['_logic'] = 'or';
        $list = D('Archives')->where($newMap)->field('id,path ename,alias_title')->group('id')->order('level asc')->page("{$page}, {$limit}")->select();
        foreach ($list as &$ls) {
            $enmae = '';
            $ls['ename'] = $this->getFullPath($ls['id'], $enmae, 0);
        }
        unset($ls);
        if (!IS_AJAX)
            return $list;
        $this->ajaxReturn($list);
        return;
        $html = '';
        foreach ($list as $vo) {
            $html .= '<li data-id="' . $vo['id'] . '"><span>' . $vo['ename'] . '</span></li>';
        }
        $this->ajaxReturn(['html' => $html]);
    }

    private function getFullPath($id, &$linkStr = '', $isHref = 1) {
        $archInfo = M('Archives')->where(['id' => $id])->field('id,parent_id,alias_title,level')->find();
        $linkStr = $archInfo['alias_title'] . '/' . $linkStr;
        if ($archInfo['parent_id'] != 0) {
            if ($isHref) {
                $this->getDirLink($archInfo['parent_id'], $linkStr, 1);
            } else {
                $this->getFullPath($archInfo['parent_id'], $linkStr, 0);
            }
        }
        return $linkStr;
    }

    //同步校区
    public function sysccampus() {
        //查询level为1的数据
        $campusIds = M('Archives')->where(['level' => 1])->getField('campus_id', true);
        //请求校区接口数据
//        $url = C('ShuhuaYeechApi') . "wechat/app/course/compus?page=1&size=1000";
//        $result = json_decode(curlGet($url), true);
//        $result = [];
        //获取校管家校区数据
        $apiData = json_decode(curlGet(C('OPWEB_SITE_HOST') . '/moniter/xiaoguanjia/xgjDepartCache'), true);
        $res = false;
        if ($apiData) {
            $insertData = [];
            foreach ($apiData as $v) {
                if (!in_array($v['dept_id'], $campusIds)) {
                    $tempInsertArr = [
                        'uid' => UID,
                        'campus_id' => $v['dept_id'],
                        'status' => 1,
                        'title' => $v['dept_id'],
                        'alias_title' => $v['name'],
                        'path' => $v['name'],
                        'type' => 0,
                        'suffix' => 'fold',
                        'create_time' => time(),
                        'modifytime' => time(),
                    ];
                    $insertData[] = $tempInsertArr;
                }
            }
            $res = true;
            if (!empty($insertData)) {
                $res = M('Archives')->addAll($insertData);
            }
        }
        $res ? $this->ajaxReturn(['status' => 1]) : $this->ajaxReturn(['status' => 0]);
    }

    //添加文件夹
    public function documentsadd() {
        $pid = I("pid", 0, "int");
        $show_pay = I("show_pay", 0, "int");
        $pdc = M("archives")->field("path,level,campus_id,term_id,course_id")->where(['id' => $pid])->find();
        if (empty($pid)) {
            $path = '/';
        } else {
            $path = !empty($pdc['path']) ? $pdc['path'] . "/" : '/';
        }
        $data['title'] = 'dc' . time() . rand(00000, 99999);
        $data['alias_title'] = "新建文件夹";
        $data['uid'] = UID;
        $data['parent_id'] = $pid;
        $data['type'] = 0;
        $data['level'] = $pdc['level'] + 1;
        $data['path'] = $path . $data['alias_title'];
        $data['campus_id'] = $pdc['campus_id'];
        $data['term_id'] = $pdc['term_id'];
        $data['course_id'] = $pdc['course_id'];
        $data['pixel'] = 0;
        $data['width'] = 0;
        $data['height'] = 0;
        $data['suffix'] = "fold";
        $data['create_time'] = time();
        $data['modifytime'] = time();
        $id = M("archives")->add($data);
        $data['id'] = $id;
        $this->assign('show_pay', $show_pay);
        $list[] = $data;
//        $result['html'] = '<li class="folderli" data-id="' . $id . '" data-url="' . U('subordinateunit', ['pid' => $id]) . '"><em></em><div contenteditable="true" data-id="' . $id . '" class="changename">' . $data['alias_title'] . '</div></li>';
        $this->assign('list', $list);
        $html = $this->fetch('datalist');
        $result['status'] = 1;
        action_log('update_archives', 'archives', $id, UID, '', '新建文件夹');
        $this->teacherFileSet();
        $this->ajaxReturn(['status' => 1, 'html' => $html]);
    }

    public function documentsupdownuser($pid = 0, $show_pay = 0, $uploadfiletype = '') {
        if (!$pid)
            return;
        $uploadFileArr = $_FILES;
        $parentInfo = M('Archives')->where(['id' => $pid])->field('id,level,campus_id,term_id,course_id')->find();
        $archivesData = [];
        $path = './Datamanager/';
        $failArr = [];
        $asTitleArr = [];
        $lastMap = [
            'parent_id' => $pid,
            'uid' => UID
        ];
        //上传压缩包，解压上传
        if ($uploadfiletype == 'zip') {
            $list = $this->uncompressDeal($uploadFileArr, $parentInfo);
            $failArr = [];
            $res = 1;
            $asTitleArr = [];
            $lastMap['type'] = 0;
            $list = M('Archives')->where($lastMap)->field('id,type,alias_title,level,path')->order('id desc')->limit(count($uploadFileArr))->select();
            action_log('update_archives', 'archives', $pid, UID, '', '上传压缩包：' . count($uploadFileArr) . '个');
        } else {
            foreach ($uploadFileArr as $file) {
                $imgType = array_pop(explode('.', $file['name']));
                $type = $this->getFileType($imgType);
                if ($type != -1) {
                    $fileTitle = time() . mt_rand(100, 999) . ".{$imgType}";
                    $new_file = $path . date('Y/m/d') . '/' . $fileTitle;
                    //文件上传
                    $upload = uploadImg_Oss(file_get_contents($file['tmp_name']), $new_file, $this->ossConfig['bucket'], $this->ossConfig);
                    //                write_log(json_encode($upload), 'datamanager');
                    if ($upload['info']['url']) {
                        $tempArr = [
                            'uid' => UID,
                            'campus_id' => $parentInfo['campus_id'],
                            'term_id' => $parentInfo['term_id'],
                            'course_id' => $parentInfo['course_id'],
                            'title' => $fileTitle,
                            'alias_title' => $file['name'],
                            'parent_id' => $pid,
                            'type' => $type,
                            'level' => $parentInfo['level'] + 1,
                            'path' => $new_file,
                            'create_time' => time(),
                            'modifytime' => time(),
                            'pixel' => $file['size'],
                            'suffix' => $imgType,
                        ];
                        $archivesData[] = $tempArr;
                        $asTitleArr[] = $file['name'];
                    } else {
                        $failArr[] = $file['name'];
                    }
                } else {
                    $failArr[] = $file['name'];
                }
            }
            $res = M('Archives')->addAll($archivesData);
            action_log('update_archives', 'archives', $pid, UID, '', '上传文件：' . count($archivesData) . '个');
            $list = M('Archives')->where($lastMap)->field('id,type,alias_title,level,path')->order('id desc')->limit(count($asTitleArr))->select();
        }
        $this->assign('list', $list);
        $html = $this->fetch('datalist');
        $this->assign('show_pay', $show_pay);
        $this->teacherFileSet();
        $result = ['faildata' => $failArr, 'instatus' => $res, 'suctitles' => $asTitleArr, 'html' => $html, 'uploadfiletype' => $uploadfiletype];
        $this->ajaxReturn($result);
    }

    private function getFileType($extName) {
        //文件类型
        /*
          1、图片 2、word文档 3、txt 4、视频 5、excel 6、ppt 7、pdf 8、压缩包 9、音频 10、xmind
         *          */
        $imgType = ["png", "jpg", "jpeg", "gif", "bmp"];
        $wordType = ["doc", "docx"];
        $txtType = ["txt"];
        $videoType = ["flv", "swf", "mkv", "avi", "rm", "rmvb", "mpeg", "mpg", "ogg", "ogv", "mov", "wmv", "mp4", "webm"];
        $excelType = ["xls", "xlsx"];
        $pptType = ["ppt", "pptx"];
        $pdfType = ["pdf"];
        $zipType = ["rar", "zip", "tar", "gz", "7z", "bz2"];
        $musicType = ["mp3"];
        $xmindType = ["xmind"];
        $extName = strtolower($extName);
        if (in_array($extName, $imgType)) {
            return 1;
        }
        if (in_array($extName, $wordType)) {
            return 2;
        }
        if (in_array($extName, $txtType)) {
            return 3;
        }
        if (in_array($extName, $videoType)) {
            return 4;
        }
        if (in_array($extName, $excelType)) {
            return 5;
        }
        if (in_array($extName, $pptType)) {
            return 6;
        }
        if (in_array($extName, $pdfType)) {
            return 7;
        }
        if (in_array($extName, $zipType)) {
            return 8;
        }
        if (in_array($extName, $musicType)) {
            return 9;
        }
        if (in_array($extName, $xmindType)) {
            return 10;
        }
        return -1;
    }

    function uploadlocal($dir, $pid = 0) {
        $files = array();
        if (@$handle = opendir($dir)) { //注意这里要加一个@，不然会有warning错误提示：）
            while (($file = readdir($handle)) !== false) {
                if ($file != ".." && $file != ".") { //排除根目录；
                    if (is_dir($dir . "/" . $file)) { //如果是子文件夹，就进行递归
                        $data['title'] = 'dc' . time() . rand(00000, 99999);
                        $data['alias_title'] = $file;
                        $data['parent_id'] = $pid;
                        $data['type'] = 0;
                        $data['path'] = $dir . "/" . $file;
                        $data['pixel'] = 0;
                        $data['width'] = 0;
                        $data['height'] = 0;
                        $data['suffix'] = "";
                        $data['recordtime'] = time();
                        $data['modifytime'] = time();
                        $rs = M("archives")->add($data);
                        $files[$file] = $this->uploadlocal($dir . "/" . $file, $rs);
                    } else { //不然就将文件的名字存入数组；
                        $ext = strrchr($file, '.');
                        if ($ext != ".jpeg" && $ext != ".jpg" && $ext != ".png" && $ext != ".gif") {
                            continue;
                        }
                        $img = getimagesize($dir . "/" . $file);
                        $data['title'] = 'dc' . time() . rand(00000, 99999) . $ext;
                        $data['alias_title'] = $file;
                        $data['parent_id'] = $pid;
                        $data['type'] = 1;
                        $data['path'] = $dir . "/" . $file;
                        $data['pixel'] = filesize($dir . "/" . $file);
                        $data['width'] = $img[0];
                        $data['height'] = $img[1];
                        $data['suffix'] = trim($ext, ".");
                        $data['recordtime'] = time();
                        $data['modifytime'] = time();
                        M("archives")->add($data);
                        $files[] = $data;
                    }
                }
            }
            closedir($handle);
            return $files;
        }
    }

    public function documentsdel() {
        $ids = I("ids");
        if (empty($ids)) {
            $this->ajaxReturn(array("status" => 0, "msg" => "删除失败"));
        }
        $idarr = explode(",", trim($ids, ','));
        //判断是否存在子数据
        if (M("archives")->where(['parent_id' => ['in', $idarr]])->find()) {
            $this->ajaxReturn(array("status" => 0, "msg" => "删除失败,有文件夹下存在数据!"));
            return;
        }
        M("archives")->where(['id' => ['in', $idarr]])->delete();
        action_log('update_archives', 'archives', 1, UID, '', '删除文件：' . count($idarr) . '个' . $ids);
        $this->teacherFileSet();
        $this->ajaxReturn(array("status" => 1, "msg" => "删除成功"));
    }

    public function chagedcname() {
        $id = I('id', 0);
        $changename = I('changename', '', 'trim');
        if ($changename) {
            //修改子文件夹的path
            $archInfo = M("archives")->where(['id' => $id])->field('path,type,alias_title')->find();
            if ($archInfo['alias_title'] == $changename)
                $this->ajaxReturn(array("status" => 2, "msg" => "未修改文件夹"));
            $updateData = [
                'alias_title' => $changename
            ];
            if ($archInfo['type'] == 0) {
                $updateData['path'] = substr($archInfo['path'], 0, strrpos($archInfo['path'], '/')) . '/' . $changename;
            }
            M("archives")->where(['id' => $id])->save($updateData);
            $this->updateChildDir($id);
            action_log('update_archives', 'archives', $id, UID, '', $archInfo['alias_title'] . '重命名为:' . $changename);
        }
        $this->ajaxReturn(array("status" => 1, "msg" => "修改成功"));
    }

    //修改字文件夹path
    private function updateChildDir($id) {
        $dirData = M("archives")->where(['parent_id' => $id, 'type' => 0])->field('id,path,alias_title')->select();
        if (!empty($dirData)) {
            $path = M("archives")->where(['id' => $id])->getField('path');
            foreach ($dirData as $vo) {
                $updata = [
                    'path' => rtrim($path, '/') . '/' . $vo['alias_title']
                ];
                M("archives")->where(['id' => $vo['id']])->save($updata);
                $this->updateChildDir($vo['id']);
            }
        }
    }

    public function movedata() {
        $pid = I('post.pid', 0);
        $ids = I('post.ids', '');
        $idsArr = explode(',', rtrim($ids, ','));
        $parentInfo = M('Archives')->where(['id' => $pid])->field('campus_id,term_id,course_id,level,path,alias_title')->find();
        //修改选中数据的parent_id,path
        foreach ($idsArr as $v) {
            $currentInfo = M('Archives')->where(['id' => $v])->field('type,path')->find();
            $updata = [
                'campus_id' => $parentInfo['campus_id'],
                'term_id' => $parentInfo['term_id'],
                'course_id' => $parentInfo['course_id'],
                'parent_id' => $pid,
                'level' => $parentInfo['level'] + 1,
                'modifytime' => time()
            ];
            if ($currentInfo['type'] == 0) {
                $updata['path'] = $parentInfo['path'] . '/' . $currentInfo['alias_title'];
                M('Archives')->where(['id' => $v])->save($updata);
                $this->updateChild($v);
            } else {
                M('Archives')->where(['id' => $v])->save($updata);
            }
        }
        action_log('update_archives', 'archives', $pid, UID, '', '移动文件' . $ids . '至' . $parentInfo['alias_title']);
        $this->ajaxReturn(['status' => 1]);
    }

    //修改目录下级数据
    private function updateChild($pid) {
        //查找是否存在下级
        $parentInfo = M('Archives')->where(['id' => $pid])->field('campus_id,term_id,course_id,level,path')->find();
        $idsArr = M('Archives')->where(['parent_id' => $pid])->getField('id', true);
        foreach ($idsArr as $v) {
            $currentInfo = M('Archives')->where(['id' => $v])->field('type,path')->find();
            $updata = [
                'campus_id' => $parentInfo['campus_id'],
                'term_id' => $parentInfo['term_id'],
                'course_id' => $parentInfo['course_id'],
                'parent_id' => $pid,
                'level' => $parentInfo['level'] + 1,
                'modifytime' => time()
            ];
            if ($currentInfo['type'] == 0) {
                $updata['path'] = $parentInfo['path'] . '/' . $currentInfo['alias_title'];
                M('Archives')->where(['id' => $v])->save($updata);
                $this->updateChild($currentInfo['id']);
            } else {
                M('Archives')->where(['id' => $v])->save($updata);
            }
        }
    }

    public function searcamp($pca = '') {
        if ($pca) {
            $pcaArr = explode('|', trim($pca, '|'));
            $pcaCount = count($pcaArr);
            //省
            if ($pcaCount == 1)
                $result = M('Campus', '', 'DB_SHUHUASYSTEM')->where(['province' => $pcaArr[0]])->getField('id', true);
            //市    
            if ($pcaCount == 2)
                $result = M('Campus', '', 'DB_SHUHUASYSTEM')->where(['city' => $pcaArr[1]])->getField('id', true);
            //区
            if ($pcaCount == 3)
                $result = M('Campus', '', 'DB_SHUHUASYSTEM')->where(['district' => $pcaArr[2]])->getField('id', true);
            $this->ajaxReturn($result);
        }
    }

    public function backdownload($ids = '') {
        $this->advanceDownload($ids);
        return;
        $ids = explode(',', rtrim($ids, ','));
        $tmpFile = tempnam('/tmp', '');
        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZIPARCHIVE::CREATE);
        $datalist = M('Archives')->where(['id' => ['in', $ids], 'type' => ['neq', 4], 'status' => 1])->field('id,type,alias_title,path')->select();
        foreach ($datalist as $k => $val) {
            if ($val['type'] != 0) {
                $fileContent = $this->getFileContent(getImageUrlByPath($val['path']));
                $fileName = str_replace('\\', '/', iconv("utf-8", "GBK", $val['alias_title']));
                $zip->addFromString($fileName, $fileContent);
                ob_flush();
                //每次调用完压缩算法把输出缓存挤出去
                flush();
            } else {
                $this->zipAddFile($zip, $val['alias_title'], $val['id']);
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename=下载数据.zip');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        unlink($tmpFile);
        return;
    }

    private function zipAddFile(&$zip, &$alias_title, $id) {
        $map = [
            'parent_id' => $id,
            'status' => 1
        ];
        if ($this->perssion != 1) {
            //下载指定老师
            if ($this->perssion == 2) {
                $uids = M('ArchivesLimit')->where(['uid' => UID, 'type' => 2])->getField('ids');
                $uids = explode(',', $uids);
                $uids[] = UID;
                $map['uid'] = ['in', $uids];
            }
            if ($this->perssion == 0) {
                $map['uid'] = UID;
            }
        }
        $archData = M('Archives')->where()->field('id,parent_id,type,alias_title,path')->select();
        $tempDir = $alias_title;
        if (empty($archData)) {
//            write_log($tempDir, 'datamanager');
            $zip->addEmptyDir($tempDir);
            ob_flush();
            //每次调用完压缩算法把输出缓存挤出去
            flush();
        } else {
            foreach ($archData as $val) {
                if ($val['type'] != 0) {
                    $fileName = str_replace('\\', '/', iconv("utf-8", "GBK", $tempDir . '/' . $val['alias_title']));
                    $fileContent = $this->getFileContent(getImageUrlByPath($val['path'], 0, 'auto', '', '', $this->ossConfig['bucket'], 3600, $this->ossConfig));
//                    write_log($fileName, 'datamanager');
                    $zip->addFromString($fileName, $fileContent);
                    ob_flush();
                    //每次调用完压缩算法把输出缓存挤出去
                    flush();
                } else {
                    $alias_title = $tempDir . '/' . $val['alias_title'];
//                    write_log($alias_title, 'datamanager');
                    $this->zipAddFile($zip, $alias_title, $val['id']);
                }
            }
        }
    }

    private function getFileContent($url = '') {
//        write_log($url, 'datamanager');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        $fileContent = curl_exec($ch);
        curl_close($ch);
        return $fileContent;
    }

    public function advanceDownload($ids = '') {
        if (!$ids)
            return;
        $ids = explode(',', rtrim($ids, ','));

        $datalist = M('Archives')->where(['id' => ['in', $ids], 'status' => 1])->field('id,type,alias_title,path,parent_id')->order('type asc,level asc')->select();
//        $filePath = '/download_' . time() . rand(100, 999) . '/';
        $filePath = '/';
        $downDir = rtrim(C('ARCHIVES.TEMP')['PATH'], '/') . $filePath;
        mkdir($downDir);
        $zip = new \ZipArchive();
        $downFileName = time() . rand(100, 999) . '.zip';
        $tmpFile = $downDir . $downFileName;
        $zip->open($tmpFile, \ZIPARCHIVE::CREATE);
        $fileParentArr = [];
        $map['status'] = 1;
        if ($this->perssion != 1) {
            //查看指定老师
            if ($this->perssion == 2) {
                $staffIds = M('ArchivesLimit')->where(['uid' => is_login(), 'type' => 2])->getField('ids');
                $uids = M('Member')->where(['staff_id' => ['in', explode(',', trim($staffIds, ','))]])->getField('uid', true);
                $uids[] = UID;
                $map['uid'] = ['in', $uids];
            }
            if ($this->perssion == 0) {
                $map['uid'] = UID;
            }
        }
        $this->addFiletoZip($zip, $datalist, $downDir, $fileParentArr, $map);
//        foreach ($datalist as $vo) {
//            if ($vo['type'] != 0) {
//                $tempDir = '';
//                $fileLocalPath = $downDir;
//                if (isset($fileParentArr[$vo['parent_id']])) {
//                    $tempDir = $fileParentArr[$vo['parent_id']];
//                    $fileLocalPath = $downDir . $tempDir;
//                }
//                //将远程文件写到本地
//                file_put_contents($fileLocalPath . $vo['alias_title'], $this->getFileContent(getImageUrlByPath($vo['path'],0,'auto','','',$this->ossConfig['bucket'],3600,$this->ossConfig)));
//                $fileName = str_replace('\\', '/', iconv("utf-8", "GBK", $tempDir . $vo['alias_title']));
//                //将服务器文件添加到压缩包
//                $zip->addFile($fileLocalPath . $vo['alias_title'], $fileName);
//                ob_flush();
//                //每次调用完压缩算法把输出缓存挤出去
//                flush();
//            } else {
//                $fileDir = $downDir . $vo['alias_title'] . '/';
//                $tempDir = $vo['alias_title'] . '/';
//                if (isset($fileParentArr[$vo['parent_id']]))
//                    $tempDir = $fileParentArr[$vo['parent_id']] . '/' . $vo['alias_title'] . '/';
//                $fileParentArr[$vo['id']] = $tempDir;
//                mkdir($fileDir);
//                $zip->addEmptyDir($tempDir);
//                ob_flush();
//                //每次调用完压缩算法把输出缓存挤出去
//                flush();
//                //获取当前文件夹的文件
//                $childFiles = M('Archives')->where(['parent_id' => $vo['id'], 'type' => ['neq', 0]])->field('id,type,alias_title,path,parent_id')->select();
//                foreach ($childFiles as $cf) {
//                    //将远程文件写到本地
//                    file_put_contents($fileDir . $cf['alias_title'], $this->getFileContent(getImageUrlByPath($cf['path'],0,'auto','','',$this->ossConfig['bucket'],3600,$this->ossConfig)));
//                    $fileName = str_replace('\\', '/', iconv("utf-8", "GBK", $tempDir . $cf['alias_title']));
//                    //将服务器文件添加到压缩包
//                    $zip->addFile($fileDir . $cf['alias_title'], $fileName);
//                    ob_flush();
//                    //每次调用完压缩算法把输出缓存挤出去
//                    flush();
//                }
//            }
//        }
        $zip->close();
        action_log('update_archives', 'archives', 1, UID, '', '下载文件' . json_encode($ids));
        $this->success(rtrim(C('ARCHIVES.TEMP')['HOST'], '/') . $filePath . $downFileName);
        return;
    }

    private function addFiletoZip(&$zip, $datalist, $downDir, $fileParentArr,$map=[]) {
        foreach ($datalist as $vo) {
            if ($vo['type'] != 0) {
                $tempDir = '';
                $fileLocalPath = $downDir;
                if (isset($fileParentArr[$vo['parent_id']])) {
                    $tempDir = $fileParentArr[$vo['parent_id']];
                    $fileLocalPath = $downDir . $tempDir;
                }
                //将远程文件写到本地
                file_put_contents($fileLocalPath . $vo['alias_title'], $this->getFileContent(getImageUrlByPath($vo['path'], 0, 'auto', '', '', $this->ossConfig['bucket'], 3600, $this->ossConfig)));
                $fileName = str_replace('\\', '/',$tempDir . $vo['alias_title']);
//                write_log($fileName);
                //将服务器文件添加到压缩包
                $zip->addFile($fileLocalPath . $vo['alias_title'], $fileName);
                ob_flush();
                //每次调用完压缩算法把输出缓存挤出去
                flush();
            } else {
                $fileDir = $downDir .'/'. $vo['alias_title'] . '/';
                $tempDir = $vo['alias_title'] . '/';
                if (isset($fileParentArr[$vo['parent_id']]))
                    $tempDir = $fileParentArr[$vo['parent_id']] . '/' . $vo['alias_title'] . '/';
                $fileParentArr[$vo['id']] = $tempDir;
                mkdir($fileDir);
                $zip->addEmptyDir($tempDir);
                ob_flush();
                //每次调用完压缩算法把输出缓存挤出去
                flush();
                //获取当前文件夹的文件
                $map['parent_id'] = $vo['id'];
                $childFiles = M('Archives')->where($map)->field('id,type,alias_title,path,parent_id')->order('type asc,level asc')->select();
                $this->addFiletoZip($zip, $childFiles, $downDir, $fileParentArr,$map);
//                foreach ($childFiles as $cf) {
//                    //将远程文件写到本地
//                    file_put_contents($fileDir . $cf['alias_title'], $this->getFileContent(getImageUrlByPath($cf['path'], 0, 'auto', '', '', $this->ossConfig['bucket'], 3600, $this->ossConfig)));
//                    $fileName = str_replace('\\', '/', iconv("utf-8", "GBK", $tempDir . $cf['alias_title']));
//                    //将服务器文件添加到压缩包
//                    $zip->addFile($fileDir . $cf['alias_title'], $fileName);
//                    ob_flush();
//                    //每次调用完压缩算法把输出缓存挤出去
//                    flush();
//                }
            }
        }
    }

    private function teacherFileSet() {
        $weiboCount = M('Archives')->where(['uid' => UID, 'stauts' => 1, 'type' => ['gt', 0], 'level' => ['gt', 3]])->count('id');
        $folderCount = M('Archives')->where(['uid' => UID, 'stauts' => 1, 'type' => 0, 'level' => ['gt', 3]])->count('id');
        $data = [
            'modify_time' => time(),
            'weibo_count' => $weiboCount,
            'folder_count' => $folderCount
        ];
        $extArch = M('ArchivesTeacher')->where(['uid' => UID])->find();
        if ($extArch) {
            M('ArchivesTeacher')->where(['uid' => UID])->save($data);
        } else {
            $staff_id = M('Member')->where(['uid' => UID])->getField('staff_id');
            $staffInfo = M('Staff', '', 'DB_SHUHUASYSTEM')->where(['id' => $staff_id])->find();
            $data['uid'] = UID;
            $data['staff_id'] = $staff_id;
            $data['name'] = $staffInfo['name'] ? $staffInfo['name'] : '管理员';
            $data['create_time'] = time();
            M('ArchivesTeacher')->add($data);
        }
    }

    public function searchFile() {
        $searchVal = I('search_val', '');
        $searchLevel = I('search_level', 1);
        $searchPid = I('search_pid', 0);
        $map['alias_title'] = ['like', '%' . $searchVal . '%'];
        if (empty($searchVal)) {
            $map['level'] = $searchLevel;
            $map['parent_id'] = $searchPid;
            unset($map['alias_title']);
        }
        $map['id'] = ['in', $this->getChildIds($searchPid)];
        $map['status'] = 1;
        if ($this->perssion != 1) {
            $map['level'] = ['egt', $searchLevel];
            //查看指定老师
            if ($this->perssion == 2) {
                $staffIds = M('ArchivesLimit')->where(['uid' => is_login(), 'type' => 2])->getField('ids');
                $uids = M('Member')->where(['staff_id' => ['in', explode(',', trim($staffIds, ','))]])->getField('uid', true);
                $uids[] = UID;
                $map['uid'] = ['in', $uids];
            }
            if ($this->perssion == 0) {
                $map['uid'] = UID;
            }
        }
        $data = M('Archives')->where($map)->order('type asc,id asc')->select();
        $this->assign('list', $data);
        $html = $this->fetch('datalist');
        $this->ajaxReturn(['html' => $html]);
    }

    private function getChildIds($id, &$childArr = []) {
        $ids = M('Archives')->where(['parent_id' => $id])->getField('id', true);
        if ($ids) {
            foreach ($ids as $childId) {
                $childArr[] = $childId;
                $this->getChildIds($childId, $childArr);
            }
        }
        return $childArr;
    }

    public function setShowWay($islist = 0) {
        $islist ? cookie('listshowpay', $islist, 7200) : cookie('listshowpay', null);
    }

    public function uploadDir() {
        $dirName = './Runtime/测试';
        $dir_path = iconv('UTF-8', 'GBK', $dirName);
        $parentInfo = M('Archives')->where(['id' => 20376])->field('id,level,campus_id,term_id,course_id,type,alias_title,level,path')->find();
        $this->dirFileDeal($parentInfo, $dir_path);
    }

    //处理解压缩
    private function uncompressDeal($uploadFileArr, $parentInfo) {
        $archivesData = [];
        $filePath = '/';
        $unzipDir = rtrim(C('ARCHIVES.TEMP')['PATH'], '/') . $filePath;
        foreach ($uploadFileArr as $file) {
            $dirName = array_shift(explode('.', $file['name']));
            exec('unzip ' . $file['tmp_name'] . ' -d ' . $unzipDir);
            $dirId = $this->createDir($parentInfo, $dirName);
            $nowFoldInfo = M('Archives')->where(['id' => $dirId])->field('id,level,campus_id,term_id,course_id,type,alias_title,level,path')->find();
            $dirPath = iconv('UTF-8', 'GBK', $unzipDir . $dirName);
            //处理解压后文件夹里的数据
            $this->dirFileDeal($nowFoldInfo, $dirPath);
            //删除文件夹
            unlink($dirPath);
        }
    }

    private function dirFileDeal($parentInfo, $dir_path) {
        if (is_dir($dir_path)) {
            $dirs = opendir($dir_path);
            while (($file = readdir($dirs)) !== false) {
                if ($file !== '.' && $file !== '..') {
                    $filePath = rtrim($dir_path, '/') . '/' . $file;
                    if (is_dir($dir_path . "/" . $file)) {
                        $dirId = $this->createDir($parentInfo, iconv('GBK', 'UTF-8', $file));
                        $newDirInfo = M('Archives')->where(['id' => $dirId])->field('id,level,campus_id,term_id,course_id')->find();
                        $this->dirFileDeal($newDirInfo, $filePath);
                    } else {
                        $this->createFile($parentInfo, $filePath);
                    }
                }
            }
        } else {
            $this->createFile($parentInfo, $dir_path);
            return;
        }
    }

    private function createDir($parentInfo, $dirName, $course_id = '') {
        $dirData = [
            'uid' => UID,
            'campus_id' => $parentInfo['campus_id'],
            'term_id' => $parentInfo['term_id'],
            'course_id' => $course_id ? $course_id : $parentInfo['course_id'],
            'title' => $dirName,
            'alias_title' => $dirName,
            'parent_id' => $parentInfo['id'],
            'type' => 0,
            'level' => $parentInfo['level'] + 1,
            'path' => $parentInfo['path'] . '/' . $dirName,
            'create_time' => time(),
            'modifytime' => time(),
            'pixel' => 0,
            'suffix' => 'fold',
        ];
        return M('Archives')->add($dirData);
    }

    private function createFile($parentInfo, $file) {
        $path = './Datamanager/';
        $imgType = array_pop(explode('.', $file));
        $type = $this->getFileType($imgType);
        if ($type != -1) {
            $fileTitle = time() . mt_rand(100, 999) . ".{$imgType}";
            $new_file = $path . date('Y/m/d') . '/' . $fileTitle;
            //文件上传
            $upload = uploadImg_Oss(file_get_contents($file), $new_file, $this->ossConfig['bucket'], $this->ossConfig);
            //                write_log(json_encode($upload), 'datamanager');
            if ($upload['info']['url']) {
                $fileName = array_pop(explode('/', iconv('GBK', 'UTF-8', $file)));
                $tempArr = [
                    'uid' => UID,
                    'campus_id' => $parentInfo['campus_id'],
                    'term_id' => $parentInfo['term_id'],
                    'course_id' => $parentInfo['course_id'],
                    'title' => $fileTitle,
                    'alias_title' => $fileName,
                    'parent_id' => $parentInfo['id'],
                    'type' => $type,
                    'level' => $parentInfo['level'] + 1,
                    'path' => $new_file,
                    'create_time' => time(),
                    'modifytime' => time(),
                    'pixel' => filesize($file),
                    'suffix' => $imgType,
                ];
                return M('Archives')->add($tempArr);
            }
        }
    }

    public function getClassData() {
        $archivesParentId = I('get.parent_id');
        $campus_id = M('Archives')->where(['id' => $archivesParentId])->getField('campus_id');
        $classData = json_decode(curlGet(C('OPWEB_SITE_HOST') . '/moniter/xiaoguanjia/getShiftByEmployee?employee_id=' . session('xgjid') . '&campus_id=' . $campus_id), true);
        write_log('获取班级url:' . C('OPWEB_SITE_HOST') . '/moniter/xiaoguanjia/getShiftByEmployee?employee_id=' . session('xgjid') . '&campus_id=' . $campus_id, 'datamanager');
        foreach ($classData as &$cd) {
            $class = '';
            foreach ($cd['class_list'] as $cl) {
                $class .= $cl['name'] . ',';
            }
            $cd['class'] = $class;
        }
        if ($classData['code'] == 500) {
            $this->ajaxReturn(['html' => '']);
        }
        $this->assign('classData', $classData);
        $html = $this->fetch('class_list');
        $this->ajaxReturn(['html' => $html]);
    }

    //刷新同步
    public function refreshSync() {
        $parentId = I('post.parent_id', '');
        $shift_ids = I('post.shift_ids', '');
        $shift_names = I('post.shift_names', '');
        $idArr = explode(',', rtrim($shift_ids, ','));
        $nameArr = explode('{*}', rtrim($shift_names, '{*}'));
        $parentInfo = M('Archives')->where(['id' => $parentId])->find();
        foreach ($idArr as $k => $courseId) {
            $map['parent_id'] = $parentId;
            $map['course_id'] = $courseId;
            $map['uid'] = UID;
            if (!M('Archives')->where($map)->find()) {
                $this->createDir($parentInfo, $nameArr[$k], $courseId);
            }
        }
    }

}
