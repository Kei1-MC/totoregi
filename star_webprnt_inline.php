<?php /* star_webprnt_inline.php — JS ファイルの代わりに PHP インクルードで提供 */ ?>
<script>
'use strict';

var STAR_BT_URL = (location.protocol === 'https:')
    ? 'https://localhost:8001/StarWebPRNT/SendMessage'
    : 'http://localhost:8001/StarWebPRNT/SendMessage';

function isStarWebPRNTBrowser() {
    return /StarWebPRNT/i.test(navigator.userAgent);
}

/* 半角=1・全角=2として表示幅を計算（実機テスト印刷で確認した実測値: 半角46文字） */
var RECEIPT_LINE_WIDTH = 46;
function _receiptStrWidth(s) {
    var w = 0;
    for (var i = 0; i < s.length; i++) {
        w += (s.charCodeAt(i) > 0xFF) ? 2 : 1;
    }
    return w;
}
/* 「■ 部門名」を左、「¥部門小計」を右にスペースで詰めて1行にする */
function _bumonHeaderLine(bumon, bTotal) {
    var left  = '■ ' + bumon;
    var right = '\xa5' + bTotal.toLocaleString();
    var pad   = RECEIPT_LINE_WIDTH - _receiptStrWidth(left) - _receiptStrWidth(right);
    if (pad < 1) pad = 1;
    return left + new Array(pad + 1).join(' ') + right;
}

function buildStarReceiptXml(storeName, dateStr, receiptNo, groups, catOrder, grandTotal, isKakunin, barCodes, count) {
    var b   = new StarWebPrintBuilder();
    var req = '';
    req += b.createInitializationElement();

    /* ヘッダー */
    req += b.createAlignmentElement({ position: 'center' });
    req += b.createTextElement({ codepage: 'utf8', width: 2, height: 2, emphasis: 'true', data: storeName + '\n' });
    req += b.createTextElement({ width: 1, height: 1 });
    req += b.createTextElement({ emphasis: 'false' });
    req += b.createTextElement({ codepage: 'utf8', data: dateStr + '  No.' + receiptNo + (isKakunin ? '（訂正）' : '') + '\n' });
    req += b.createTextElement({ codepage: 'utf8', width: 2, height: 2, data: '明細書\n' });
    req += b.createTextElement({ width: 1, height: 1 });
    req += b.createTextElement({ codepage: 'utf8', data: '領収書ではありません\n' });
    req += b.createRuledLineElement({ thickness: 'medium', width: 576 });
    req += b.createFeedElement({ line: 1 });

    /* 部門別明細 */
    catOrder.forEach(function(bumon) {
        var bItems = groups[bumon];
        if (!bItems || !bItems.length) return;
        var bTotal = bItems.reduce(function(s, it) { return s + it.subtotal; }, 0);
        var code   = barCodes ? (barCodes[bumon] || null) : null;

        req += b.createAlignmentElement({ position: 'left' });
        req += b.createTextElement({ codepage: 'utf8', emphasis: 'true', data: _bumonHeaderLine(bumon, bTotal) + '\n' });
        req += b.createTextElement({ emphasis: 'false' });
        req += b.createRuledLineElement({ thickness: 'thin', width: 576 });

        bItems.forEach(function(it) {
            var nebStr = '';
            if (it.nebiki_ritsu > 0) {
                nebStr = ' (-' + it.nebiki_ritsu + '%)';
            } else if (it.nebiki_gaku > 0) {
                nebStr = ' (-\xa5' + it.nebiki_gaku.toLocaleString() + ')';
            }
            req += b.createAlignmentElement({ position: 'left' });
            req += b.createTextElement({ codepage: 'utf8', data: it.name + nebStr + '\n' });
            req += b.createAlignmentElement({ position: 'right' });
            req += b.createTextElement({ codepage: 'utf8', data: '\xa5' + it.subtotal.toLocaleString() + '\n' });
        });

        if (code) {
            req += b.createAlignmentElement({ position: 'center' });
            try { req += b.createBarcodeElement({ symbology: 'JAN13', width: 'width3', hri: 'true', height: 60, data: code }); } catch(e) {}
            req += b.createFeedElement({ line: 1 });
        }
        req += b.createRuledLineElement({ thickness: 'medium', width: 576 });
        req += b.createFeedElement({ line: 1 });
    });

    /* 合計 */
    req += b.createFeedElement({ line: 1 });
    req += b.createAlignmentElement({ position: 'right' });
    req += b.createTextElement({ codepage: 'utf8', width: 2, height: 2, emphasis: 'true', data: '合計  \xa5' + grandTotal.toLocaleString() + '\n' });
    req += b.createTextElement({ width: 1, height: 1 });
    req += b.createTextElement({ emphasis: 'false' });
    req += b.createAlignmentElement({ position: 'right' });
    req += b.createTextElement({ codepage: 'utf8', data: '(本体価格)\n' });
    req += b.createFeedElement({ line: 2 });

    /* フッター */
    req += b.createAlignmentElement({ position: 'center' });
    req += b.createTextElement({ codepage: 'utf8', data: 'ありがとうございました\n' });
    req += b.createFeedElement({ line: 1 });
    req += b.createTextElement({ codepage: 'utf8', data: '株式会社富惣\n' });
    req += b.createTextElement({ codepage: 'utf8', data: '大阪府堺市堺区遠里小野町３丁４番１号\n' });
    req += b.createTextElement({ codepage: 'utf8', data: 'TEL 072-229-8800 / FAX 072-229-4700\n' });
    req += b.createTextElement({ codepage: 'utf8', data: 'お問合せ 0120-014-868\n' });
    req += b.createFeedElement({ line: 4 });
    req += b.createCutPaperElement({ type: 'partial', feed: 'true' });
    return req;
}

var _starReceiptData = null;
var _starLogBuf = [];
var _starLogBtn = null;
var _STAR_LOG_URL = (function() {
    var base = location.origin;
    var path = location.pathname.replace(/\/[^/]*$/, '/');
    return base + path + 'star_debug_log.php';
}());

function _starLog(msg) {
    _starLogBuf.push(msg);
    try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', _STAR_LOG_URL, true);
        xhr.setRequestHeader('Content-Type', 'text/plain; charset=UTF-8');
        xhr.send(msg);
    } catch(e) {}
    if (_starLogBtn) _starLogBtn.textContent = msg.substring(0, 35);
}

function starPrintReceipt(btn) {
    _starLogBtn = btn;
    _starLogBuf = [];
    var isStar = isStarWebPRNTBrowser();
    _starLog('START isStar=' + (isStar ? 'YES' : 'NO'));
    _starLog('URL=' + STAR_BT_URL);
    _starLog('UA=' + navigator.userAgent.substring(0, 80));

    if (typeof StarWebPrintBuilder === 'undefined' || typeof StarWebPrintTrader === 'undefined') {
        _starLog('ERR:LIB_NOT_LOADED');
        return;
    }
    _starLog('LIB:OK');

    if (btn) { btn.disabled = true; btn.textContent = '⏳ 送信中...'; }
    function resetBtn(label) {
        if (btn) { btn.disabled = false; btn.textContent = label || '🖨 印刷'; }
    }

    if (!isStar) {
        _starLog('→ window.print()');
        window.print();
        resetBtn('🖨 印刷(通常)');
        return;
    }
    if (!_starReceiptData) {
        _starLog('ERR:DATA_NULL');
        resetBtn('ERR:DATA');
        return;
    }
    _starLog('DATA:OK store=' + _starReceiptData.storeName);

    var req;
    try {
        var d = _starReceiptData;
        var barKeys = d.barCodes ? Object.keys(d.barCodes).join(',') : 'null';
        _starLog('barCodes=' + barKeys + ' count=' + (d.count || 0));
        req = buildStarReceiptXml(d.storeName, d.dateStr, d.receiptNo, d.groups, d.catOrder, d.grandTotal, d.isKakunin || false, d.barCodes || null, d.count || null);
        _starLog('XML:OK len=' + req.length);
    } catch(e) {
        _starLog('ERR:XML ' + e.message);
        resetBtn('ERR:XML');
        return;
    }

    _starLog('SEND→ ' + STAR_BT_URL);
    var trader = new StarWebPrintTrader({ url: STAR_BT_URL, timeout: 30000 });
    trader.onReceive = function(r) { _starLog('RECV:OK success=' + r.traderSuccess + ' st=' + r.traderStatus); resetBtn('✅ 印刷完了'); };
    trader.onError   = function(r) { _starLog('ERR:status=' + r.status + ' ' + String(r.responseText).substring(0, 80)); resetBtn('❌ エラー(' + r.status + ')'); };
    trader.onTimeout = function()  { _starLog('TIMEOUT:30秒'); resetBtn('⏱ タイムアウト'); };
    try {
        trader.sendMessage({ request: req });
        _starLog('sendMessage完了(非同期)');
    } catch(e) {
        _starLog('ERR:sendMsg ' + e.message);
        resetBtn('ERR:SEND');
    }
}
</script>
