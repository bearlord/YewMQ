<?php

namespace App\Commands;


class TestController extends \Yew\Framework\Console\Controller
{

    public function actionIndex()
    {
        var_dump("test::index666");
        return 0;
    }

    public function actionProcess()
    {
        // 初始化输出缓冲
        ob_start();
        ob_implicit_flush(true);

        $total = 500;
        $barWidth = 50;

        // 隐藏光标
        echo "\033[?25l";

        for ($i = 0; $i <= $total; $i++) {
            // 计算进度
            $progress = $i / $total;
            $percent = round($progress * 100);
            $filled = floor($progress * $barWidth);

            // 生成进度条
            $bar = str_repeat('=', $filled) . '>' . str_repeat(' ', $barWidth - $filled);

            // 输出进度信息
            echo "\r[{$bar}] {$percent}% [{$i}/{$total}]";

            // 双重刷新确保输出
            ob_flush();
            flush();

            // 模拟任务处理
            usleep(50000);
        }

        // 恢复终端设置
        echo "\r\033[K\033[?25h";
        echo PHP_EOL;
    }
}