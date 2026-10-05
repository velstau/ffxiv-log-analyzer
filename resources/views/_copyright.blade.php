{{-- 権利表記（全画面の末尾）。$compact = true ならタイムライン画面用の 1 行表示 --}}
@if ($compact ?? false)
    <footer class="shrink-0 border-t border-gray-700 bg-gray-900 px-4 py-1 text-gray-400" style="font-size:1rem;">
        非公式ツール（株式会社スクウェア・エニックス・FFLogs とは無関係）。外部ツールの利用を勧めるものではありません（<a href="https://jp.finalfantasyxiv.com/lodestone/topics/detail/33dc2350ac49e2155dbc0c76ed2cdd78262e2f39" target="_blank" rel="noopener noreferrer" class="underline">公式の案内<span class="sr-only">（新しいタブで開く）</span></a>）。<span style="white-space:nowrap;">© SQUARE ENIX</span>
    </footer>
@else
    <footer class="mt-8 w-full max-w-3xl px-4 text-center text-gray-400" style="font-size:1rem; line-height:1.7;">
        <p>本ツールは、株式会社スクウェア・エニックスおよび FFLogs とは関係のない非公式のツールです。</p>
        <p>FINAL FANTASY XIV の利用規約では外部ツールの使用が禁止されています。本ツールは FFLogs で公開されているログを読むだけのもので、ゲームでの外部ツールの利用や、FFLogs へのログの記録・アップロードを勧めるものではありません（<a href="https://jp.finalfantasyxiv.com/lodestone/topics/detail/33dc2350ac49e2155dbc0c76ed2cdd78262e2f39" target="_blank" rel="noopener noreferrer" class="underline">公式の案内「FFXIV外部ツールの是非について」<span class="sr-only">（新しいタブで開く）</span></a>）。</p>
        <p>ゲーム内のアイコン・名称などの権利は株式会社スクウェア・エニックスに帰属します。<span style="white-space:nowrap;">© SQUARE ENIX</span></p>
        <p>記載されている会社名・製品名・システム名などは、各社の商標または登録商標です。</p>
    </footer>
@endif
