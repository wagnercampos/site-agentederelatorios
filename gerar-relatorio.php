<?php
require_once 'config.php';
header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Método não permitido.');
}

function s($v) { return htmlspecialchars(trim($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$cliente  = s($_POST['cliente']  ?? '');
$handle   = s($_POST['handle']   ?? '');
$segmento = s($_POST['segmento'] ?? '');
$periodo  = s($_POST['periodo']  ?? '');

if (!$cliente || !$handle || !$periodo) {
    http_response_code(400); exit('Dados obrigatórios ausentes.');
}

// ── Build multipart message content ────────────────────────
$content = [];

// Text instruction
$content[] = [
    'type' => 'text',
    'text' => "Você é um analista de marketing digital especialista em Instagram. Analise os dados/prints a seguir referentes à conta {$handle} ({$cliente}) no período de {$periodo}.

Extraia TODAS as métricas disponíveis (seguidores, crescimento, alcance, impressões, engajamento, posts, visitas ao perfil, cliques, stories, top posts etc.) e gere um relatório completo no formato JSON abaixo. Se algum dado não estiver disponível nos arquivos, use null.

Responda APENAS com o JSON válido, sem texto antes ou depois:

{
  \"seguidores\": \"valor ou null\",
  \"seg_var\": \"ex: +342 ou null\",
  \"seg_pct\": \"ex: +2,7% ou null\",
  \"alcance\": \"valor ou null\",
  \"impressoes\": \"valor ou null\",
  \"engajamento\": \"ex: 4,2% ou null\",
  \"posts\": \"valor ou null\",
  \"visitas\": \"valor ou null\",
  \"cliques\": \"valor ou null\",
  \"stories\": \"valor ou null\",
  \"top_posts\": [
    {\"caption\": \"...\", \"tipo\": \"Foto|Reels|Carrossel\", \"curtidas\": \"...\", \"comentarios\": \"...\", \"engajamento\": \"...\"},
    {\"caption\": \"...\", \"tipo\": \"...\", \"curtidas\": \"...\", \"comentarios\": \"...\", \"engajamento\": \"...\"},
    {\"caption\": \"...\", \"tipo\": \"...\", \"curtidas\": \"...\", \"comentarios\": \"...\", \"engajamento\": \"...\"}
  ],
  \"observacoes\": [
    \"Observação estratégica 1 (1-2 frases, específica, em português brasileiro)\",
    \"Observação estratégica 2\",
    \"Observação estratégica 3\",
    \"Observação estratégica 4\"
  ]
}"
];

// Attach uploaded files
$supported_images = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!empty($_FILES['arquivos']['tmp_name'])) {
    $files = $_FILES['arquivos'];
    $count = is_array($files['tmp_name']) ? count($files['tmp_name']) : 1;

    for ($i = 0; $i < $count; $i++) {
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $name = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
        $type = is_array($files['type'])     ? $files['type'][$i]     : $files['type'];
        $err  = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];

        if ($err !== UPLOAD_ERR_OK || !file_exists($tmp)) continue;

        // Detect real mime
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmp);
        finfo_close($finfo);

        if (in_array($mime, $supported_images)) {
            // Send as image
            $b64 = base64_encode(file_get_contents($tmp));
            $content[] = [
                'type' => 'text',
                'text' => "Arquivo: {$name}"
            ];
            $content[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mime,
                    'data'       => $b64
                ]
            ];
        } elseif ($mime === 'application/pdf') {
            // PDF: send as base64 document (Claude supports PDF)
            $b64 = base64_encode(file_get_contents($tmp));
            $content[] = [
                'type' => 'text',
                'text' => "Arquivo PDF: {$name}"
            ];
            $content[] = [
                'type'   => 'document',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $b64
                ]
            ];
        } else {
            // CSV / XLSX / text: read as text
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['csv', 'txt'])) {
                $text = file_get_contents($tmp);
                $content[] = [
                    'type' => 'text',
                    'text' => "Conteúdo do arquivo {$name}:\n\n" . mb_substr($text, 0, 8000)
                ];
            } elseif (in_array($ext, ['xlsx', 'xls'])) {
                // Basic XLSX: extract XML content
                $zip = new ZipArchive();
                if ($zip->open($tmp) === TRUE) {
                    $xml = $zip->getFromName('xl/sharedStrings.xml');
                    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
                    $zip->close();
                    $strings = [];
                    if ($xml) {
                        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $xml, $m);
                        $strings = $m[1];
                    }
                    $content[] = [
                        'type' => 'text',
                        'text' => "Conteúdo da planilha {$name} (strings extraídas):\n" . implode(' | ', array_slice($strings, 0, 500))
                    ];
                } else {
                    $content[] = ['type' => 'text', 'text' => "Arquivo {$name} (não foi possível ler o conteúdo)"];
                }
            } else {
                $content[] = ['type' => 'text', 'text' => "Arquivo enviado: {$name} (formato não suportado para leitura direta)"];
            }
        }
    }
}

// ── Call Claude API ─────────────────────────────────────────
$api_payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 1500,
    'messages'   => [
        ['role' => 'user', 'content' => $content]
    ]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $api_payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: pdfs-2024-09-25'
    ],
    CURLOPT_TIMEOUT        => 60,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    http_response_code(500);
    exit('Erro na API Claude (' . $http_code . '): ' . $response);
}

$result  = json_decode($response, true);
$raw     = $result['content'][0]['text'] ?? '{}';

// Extract JSON from response (strip markdown fences if present)
$raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
$raw = preg_replace('/\s*```$/', '', $raw);

$data = json_decode($raw, true);
if (!$data) {
    http_response_code(500);
    exit('Erro ao interpretar resposta da IA. Resposta recebida: ' . htmlspecialchars($raw));
}

// ── Helper: value or dash ────────────────────────────────────
function val($v) { return ($v && $v !== 'null') ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : '—'; }

// ── Build report HTML ────────────────────────────────────────
$seg_badge = '';
if (val($data['seg_var']) !== '—') {
    $seg_badge = '<span class="kpi-badge up">' . val($data['seg_var']) . ($data['seg_pct'] && $data['seg_pct'] !== 'null' ? ' · ' . val($data['seg_pct']) : '') . '</span>';
}

// Top posts HTML
$top_posts_html = '';
$ranks   = ['01', '02', '03'];
$thumbs  = ['👗', '✨', '👠'];
$bgs     = ['#fde8f0', '#fff4e0', '#f0f8ff'];
$tp      = $data['top_posts'] ?? [];
foreach (array_slice($tp, 0, 3) as $i => $p) {
    $top_posts_html .= '
    <div class="post-card">
      <div class="post-rank">' . $ranks[$i] . '</div>
      <div class="post-thumb" style="background:' . $bgs[$i] . ';">' . $thumbs[$i] . '</div>
      <div class="post-info">
        <div class="post-caption">"' . s($p['caption'] ?? '') . '"</div>
        <div class="post-type">' . s($p['tipo'] ?? '') . '</div>
      </div>
      <div class="post-stats">
        <div class="post-stat">' . val($p['curtidas'] ?? null) . ' <span>curtidas</span></div>
        <div class="post-stat">' . val($p['comentarios'] ?? null) . ' <span>coment.</span></div>
        ' . (isset($p['engajamento']) && $p['engajamento'] !== 'null' ? '<div class="post-eng">' . s($p['engajamento']) . '</div>' : '') . '
      </div>
    </div>';
}

// Observations HTML
$obs_html = '';
foreach (($data['observacoes'] ?? []) as $obs) {
    $obs_html .= '<div class="notes-item">' . s($obs) . '</div>' . "\n";
}

// Avatar initials
$words = explode(' ', $cliente);
$initials = strtoupper(substr($words[0] ?? 'X', 0, 1) . substr($words[1] ?? '', 0, 1));

// Pre-assign KPI variables for heredoc interpolation
$_seguidores  = val($data['seguidores']  ?? null);
$_alcance     = val($data['alcance']     ?? null);
$_impressoes  = val($data['impressoes']  ?? null);
$_engajamento = val($data['engajamento'] ?? null);
$_posts       = val($data['posts']       ?? null);
$_visitas     = val($data['visitas']     ?? null);
$_cliques     = val($data['cliques']     ?? null);
$_stories     = val($data['stories']     ?? null);

echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="robots" content="noindex, nofollow">
  <title>Relatório Instagram — {$cliente}</title>
  <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --teal:#3ec6c3; --orange:#ff691d; --red:#d93636; --dark:#1b1b1b; --white:#f5f5f5; --bg:#f7f7f5; --border:#e2e2dc; }
    body { font-family:'Space Mono',monospace; background:var(--bg); color:var(--dark); }
    .brand-bar { display:flex; height:6px; }
    .brand-bar span { flex:1; }
    header { background:#fff; border-bottom:1.5px solid var(--border); padding:28px 48px; display:flex; align-items:center; justify-content:space-between; }
    .header-logo img { height:52px; width:auto; display:block; }
    .header-meta { text-align:right; }
    .header-meta .report-label { font-family:'Anton',sans-serif; font-size:22px; letter-spacing:1px; text-transform:uppercase; }
    .header-meta .report-period { font-size:11px; color:#888; margin-top:3px; text-transform:uppercase; letter-spacing:1px; }
    .wrapper { max-width:900px; margin:0 auto; padding:40px 32px 64px; }
    .client-card { background:var(--dark); border-radius:4px; padding:28px 36px; display:flex; align-items:center; gap:32px; margin-bottom:36px; }
    .client-avatar { width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg,var(--orange),var(--red)); display:flex; align-items:center; justify-content:center; font-family:'Anton',sans-serif; font-size:26px; color:#fff; flex-shrink:0; }
    .client-info h2 { font-family:'Anton',sans-serif; font-size:24px; color:var(--white); }
    .client-info .handle { font-size:13px; color:var(--teal); margin-top:4px; }
    .client-info .segment { font-size:11px; color:#888; margin-top:6px; text-transform:uppercase; letter-spacing:1px; }
    .client-period { margin-left:auto; text-align:right; }
    .client-period .period-label { font-size:10px; color:#666; text-transform:uppercase; letter-spacing:1px; }
    .client-period .period-value { font-family:'Anton',sans-serif; font-size:18px; color:var(--white); margin-top:4px; }
    .section-title { font-family:'Anton',sans-serif; font-size:13px; text-transform:uppercase; letter-spacing:2px; color:#999; margin-bottom:16px; margin-top:36px; }
    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
    .kpi-card { background:#fff; border:1.5px solid var(--border); border-radius:4px; padding:20px 18px; }
    .kpi-card.highlight { border-color:var(--teal); background:#f0fdfd; }
    .kpi-label { font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#999; margin-bottom:8px; }
    .kpi-value { font-family:'Anton',sans-serif; font-size:28px; color:var(--dark); line-height:1; }
    .kpi-card.highlight .kpi-value { color:var(--teal); }
    .kpi-badge { display:inline-block; margin-top:8px; font-size:10px; font-weight:700; padding:2px 7px; border-radius:2px; }
    .kpi-badge.up { background:#e6faf7; color:#1a9e8f; }
    .posts-list { display:flex; flex-direction:column; gap:12px; }
    .post-card { background:#fff; border:1.5px solid var(--border); border-radius:4px; padding:16px 18px; display:flex; align-items:center; gap:16px; }
    .post-rank { font-family:'Anton',sans-serif; font-size:22px; color:#d9d8c7; width:28px; flex-shrink:0; }
    .post-thumb { width:44px; height:44px; border-radius:3px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
    .post-info { flex:1; }
    .post-caption { font-size:12px; font-weight:700; margin-bottom:4px; }
    .post-type { font-size:10px; color:#aaa; text-transform:uppercase; letter-spacing:1px; }
    .post-stats { display:flex; flex-direction:column; align-items:flex-end; gap:3px; }
    .post-stat { font-size:11px; font-weight:700; }
    .post-stat span { font-size:10px; font-weight:400; color:#aaa; }
    .post-eng { font-size:10px; font-weight:700; padding:2px 6px; border-radius:2px; background:#e6faf7; color:#1a9e8f; }
    .notes-box { background:#fff8f4; border:1.5px solid #ffe0cc; border-left:4px solid var(--orange); border-radius:4px; padding:20px 24px; margin-top:14px; }
    .notes-title { font-size:11px; text-transform:uppercase; letter-spacing:1px; color:var(--orange); margin-bottom:12px; font-weight:700; }
    .notes-item { font-size:12px; color:var(--dark); margin-bottom:10px; padding-left:16px; position:relative; line-height:1.6; }
    .notes-item::before { content:'→'; position:absolute; left:0; color:var(--orange); }
    .notes-item:last-child { margin-bottom:0; }
    .print-btn { display:block; margin:32px auto 0; padding:12px 32px; background:var(--dark); color:var(--white); border:none; border-radius:4px; font-family:'Anton',sans-serif; font-size:14px; letter-spacing:1.5px; text-transform:uppercase; cursor:pointer; }
    footer { background:var(--dark); margin-top:60px; padding:28px 48px; display:flex; align-items:center; justify-content:space-between; }
    .footer-dev { font-size:10px; text-transform:uppercase; letter-spacing:1.5px; color:#555; }
    .footer-logo img { height:36px; width:auto; display:block; }
    .footer-right { font-size:10px; color:#444; text-align:right; }
    @media print { .print-btn { display:none; } body { background:#fff; } }
  </style>
</head>
<body>
  <div class="brand-bar">
    <span style="background:#3ec6c3"></span><span style="background:#421700"></span>
    <span style="background:#d93636"></span><span style="background:#ff691d"></span>
    <span style="background:#d9d8c7"></span>
  </div>
  <header>
    <div class="header-logo"><img src="logo-agencia.svg" alt="Agência MOA"></div>
    <div class="header-meta">
      <div class="report-label">Relatório de Performance</div>
      <div class="report-period">Instagram · {$periodo}</div>
    </div>
  </header>
  <div class="wrapper">
    <div class="client-card">
      <div class="client-avatar">{$initials}</div>
      <div class="client-info">
        <h2>{$cliente}</h2>
        <div class="handle">{$handle}</div>
        <div class="segment">{$segmento}</div>
      </div>
      <div class="client-period">
        <div class="period-label">Período analisado</div>
        <div class="period-value">{$periodo}</div>
      </div>
    </div>

    <div class="section-title">Visão geral do período</div>
    <div class="kpi-grid">
      <div class="kpi-card highlight">
        <div class="kpi-label">Seguidores</div>
        <div class="kpi-value">{$_seguidores}</div>
        {$seg_badge}
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Alcance total</div>
        <div class="kpi-value">{$_alcance}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Impressões</div>
        <div class="kpi-value">{$_impressoes}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Eng. médio</div>
        <div class="kpi-value">{$_engajamento}</div>
      </div>
    </div>
    <div class="kpi-grid" style="margin-top:14px;">
      <div class="kpi-card">
        <div class="kpi-label">Posts</div>
        <div class="kpi-value">{$_posts}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Visitas ao perfil</div>
        <div class="kpi-value">{$_visitas}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Cliques no link</div>
        <div class="kpi-value">{$_cliques}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Views Stories</div>
        <div class="kpi-value">{$_stories}</div>
      </div>
    </div>

    <div class="section-title">Top posts do período</div>
    <div class="posts-list">
      {$top_posts_html}
    </div>

    <div class="section-title">Observações e recomendações</div>
    <div class="notes-box">
      <div class="notes-title">Análise do período — {$periodo}</div>
      {$obs_html}
    </div>

    <button class="print-btn" onclick="window.print()">Imprimir / Salvar PDF</button>
  </div>
  <footer>
    <div class="footer-dev">Desenvolvido por</div>
    <div class="footer-logo"><img src="logo-moalabs.svg" alt="MOA.labs"></div>
    <div class="footer-right">{$periodo}<br><span style="color:#333;">agenciamoa.com.br</span></div>
  </footer>
</body>
</html>
HTML;
