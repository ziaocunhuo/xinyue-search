<?php

namespace app\api\controller;

use app\api\QfShop;
use think\facade\Cache;
use app\model\Source as SourceModel;
use app\model\SourceCategory as SourceCategoryModel;

class Search extends QfShop
{
    public function index()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getList(input(''));

        // 记录热搜词（带 IP 冷却防刷）
        $title = input('title', '');
        if (!empty($title) && mb_strlen($title, 'UTF-8') >= 2) {
            $ip = request()->ip();
            $lockKey = 'hs_' . md5($ip . '_' . $title);
            if (!Cache::get($lockKey)) {
                Cache::set($lockKey, 1, 300);
                recordHotSearch(trim($title));
            }
        }

        return jok('获取成功',$data);
    }

    public function getDetail()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getDetail(input(''));
        return jok('获取成功',$data);
    }

    public function getNew()
    {
        $SourceModel = new SourceModel();
        $data = input('');
        $data['page_size'] = $data['page_size']??20;
        $data = $SourceModel->getNew($data);
        return jok('获取成功',$data);
    }

    public function getHot()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getHot(input(''));
        return jok('获取成功',$data);
    }

    public function getCategory()
    {
        $SourceCategoryModel = new SourceCategoryModel();
        $data = $SourceCategoryModel->getList(input(''));
        return jok('获取成功',$data);
    }

    /**
     * 获取热搜词列表
     */
    public function getHotSearch()
    {
        $limit = (int)(input('limit', 20));
        $limit = max(1, min(50, $limit));
        return jok('获取成功', getHotSearchList($limit));
    }
}
