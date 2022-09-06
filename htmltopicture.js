//需要引入canvas2image.js和html2canvas.js
//要处理的区域 begenerated
//显示的区域 beshow
//隐藏的区域 behide
function convert2canvas(begenerated, beshow, behide,defineScale = 2) {
    var shareContent = begenerated; //需要截图的包裹的（原生的）DOM 对象
    var width = shareContent.offsetWidth; //获取dom 宽度
    var height = shareContent.offsetHeight; //获取dom 高度
    var canvas = document.createElement("canvas"); //创建一个canvas节点
    var scale = defineScale; //定义任意放大倍数 支持小数
    canvas.width = width * scale; //定义canvas 宽度 * 缩放
    canvas.height = height * scale; //定义canvas高度 *缩放
    canvas.getContext("2d").scale(scale, scale); //获取context,设置scale
    var opts = {
        allowTaint: true, //允许污染
        taintTest: true, //在渲染前测试图片(没整明白有啥用)
        scale: scale, // 添加的scale 参数
        canvas: canvas, //自定义 canvas
        width: width, //dom 原始宽度
        height: height,
        useCORS: true // 【重要】开启跨域配置
    };
    html2canvas(shareContent, opts).then(function (canvas) {
        var context = canvas.getContext('2d');
        // 【重要】关闭抗锯齿
        context.mozImageSmoothingEnabled = false;
        context.webkitImageSmoothingEnabled = false;
        context.msImageSmoothingEnabled = false;
        context.imageSmoothingEnabled = false;
        // 【重要】默认转化的格式为png,也可设置为其他格式
//        var img = Canvas2Image.convertToJPEG(canvas, canvas.width, canvas.height);
        var img = Canvas2Image.convertToPNG(canvas, canvas.width, canvas.height);
        beshow.attr('src', $(img).attr('src'));
        if ($('.a-img').attr("src") == '') {
            $('.a-img').attr('src', $(img).attr('src'));
        }
        beshow.css({
            "width": canvas.width / 2 + "px",
            "height": canvas.height / 2 + "px",
        });
        behide.hide();
        beshow.show();
        beshow.css('display', 'block');
        $('.loading-div').hide();
    });
}



