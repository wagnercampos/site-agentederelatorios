<?php
require_once 'config.php';
header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    exit('Dados inválidos.');
}

// ── Sanitize inputs ──────────────────────────────────────────
function s($v) { return htmlspecialchars(trim($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$cliente      = s($data['cliente']);
$handle       = s($data['handle']);
$segmento     = s($data['segmento']);
$periodo      = s($data['periodo']);
$seguidores   = s($data['seguidores']);
$seg_var      = s($data['seg_var']);
$seg_pct      = s($data['seg_pct']);
$alcance      = s($data['alcance']);
$impressoes   = s($data['impressoes']);
$engajamento  = s($data['engajamento']);
$posts        = s($data['posts']);
$visitas      = s($data['visitas']);
$cliques      = s($data['cliques']);
$stories      = s($data['stories']);

$top_posts = $data['top_posts'] ?? [];

// ── Build prompt ─────────────────────────────────────────────
$posts_text = '';
foreach ($top_posts as $i => $p) {
    $n = $i + 1;
    $caption   = s($p['caption'] ?? '');
    $tipo      = s($p['tipo'] ?? '');
    $curtidas  = s($p['curtidas'] ?? '');
    $comentarios = s($p['comentarios'] ?? '');
    $eng_post  = s($p['engajamento'] ?? '');
    $posts_text .= "{$n}. \"{$caption}\" ({$tipo}) — {$curtidas} curtidas, {$comentarios} comentários, engajamento: {$eng_post}\n";
}

$prompt = <<<PROMPT
Você é um analista de marketing digital especialista em Instagram. Com base nos dados abaixo, escreva 4 observações e recomendações estratégicas para o cliente. Seja direto, profissional e específico. Escreva em português brasileiro. Não use introduções genéricas. Cada observação deve ter 1-2 frases e começar com o ponto principal.

Cliente: {$cliente}
Instagram: {$handle}
Segmento: {$segmento}
Período: {$periodo}

MÉTRICAS DO PERÍODO:
- Seguidores: {$seguidores} (variação: {$seg_var} / {$seg_pct})
- Alcance total: {$alcance}
- Impressões: {$impressoes}
- Engajamento médio: {$engajamento}
- Posts publicados: {$posts}
- Visitas ao perfil: {$visitas}
- Cliques no link: {$cliques}
- Views de Stories: {$stories}

TOP POSTS DO PERÍODO:
{$posts_text}

Retorne APENAS as 4 observações, uma por linha, sem numeração, sem bullet points, sem formatação markdown. Apenas o texto corrido de cada observação separado por quebra de linha dupla.
PROMPT;

// ── Call Claude API ──────────────────────────────────────────
$api_payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 600,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt]
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
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    http_response_code(500);
    exit('Erro ao chamar API: ' . $response);
}

$result   = json_decode($response, true);
$obs_raw  = $result['content'][0]['text'] ?? '';
$obs_list = array_filter(array_map('trim', explode("\n\n", $obs_raw)));

// ── Render HTML report ───────────────────────────────────────
$obs_html = '';
foreach ($obs_list as $obs) {
    $obs_html .= '<div class="notes-item">' . nl2br(s($obs)) . '</div>' . "\n";
}

$top_posts_html = '';
$ranks = ['01', '02', '03'];
$thumbs = ['👗', '✨', '👠'];
$thumb_bgs = ['#fde8f0', '#fff4e0', '#f0f8ff'];
foreach ($top_posts as $i => $p) {
    if ($i >= 3) break;
    $rank    = $ranks[$i];
    $thumb   = $thumbs[$i];
    $bg      = $thumb_bgs[$i];
    $caption = s($p['caption'] ?? '');
    $tipo    = s($p['tipo'] ?? 'Post');
    $curt    = s($p['curtidas'] ?? '—');
    $coment  = s($p['comentarios'] ?? '—');
    $eng_p   = s($p['engajamento'] ?? '—');
    $top_posts_html .= <<<HTML
<div class="post-card">
  <div class="post-rank">{$rank}</div>
  <div class="post-thumb" style="background:{$bg};">{$thumb}</div>
  <div class="post-info">
    <div class="post-caption">"{$caption}"</div>
    <div class="post-type">{$tipo}</div>
  </div>
  <div class="post-stats">
    <div class="post-stat">{$curt} <span>curtidas</span></div>
    <div class="post-stat">{$coment} <span>coment.</span></div>
    <div class="post-eng">{$eng_p}</div>
  </div>
</div>
HTML;
}

// ── Output full HTML ─────────────────────────────────────────
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
    :root {
      --teal: #3ec6c3; --orange: #ff691d; --red: #d93636;
      --brown: #421700; --cream: #d9d8c7; --dark: #1b1b1b;
      --white: #f5f5f5; --bg: #f7f7f5; --border: #e2e2dc;
    }
    body { font-family: 'Space Mono', monospace; background: var(--bg); color: var(--dark); }
    .brand-bar { display: flex; height: 6px; }
    .brand-bar span { flex: 1; }
    header { background: #fff; border-bottom: 1.5px solid var(--border); padding: 28px 48px; display: flex; align-items: center; justify-content: space-between; }
    .header-logo img { height: 52px; width: auto; display: block; }
    .header-meta { text-align: right; }
    .header-meta .report-label { font-family: 'Anton', sans-serif; font-size: 22px; letter-spacing: 1px; text-transform: uppercase; }
    .header-meta .report-period { font-size: 11px; color: #888; margin-top: 3px; text-transform: uppercase; letter-spacing: 1px; }
    .wrapper { max-width: 900px; margin: 0 auto; padding: 40px 32px 64px; }
    .client-card { background: var(--dark); border-radius: 4px; padding: 28px 36px; display: flex; align-items: center; gap: 32px; margin-bottom: 36px; }
    .client-avatar { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, var(--orange), var(--red)); display: flex; align-items: center; justify-content: center; font-family: 'Anton', sans-serif; font-size: 26px; color: #fff; flex-shrink: 0; }
    .client-info h2 { font-family: 'Anton', sans-serif; font-size: 24px; color: var(--white); }
    .client-info .handle { font-size: 13px; color: var(--teal); margin-top: 4px; }
    .client-info .segment { font-size: 11px; color: #888; margin-top: 6px; text-transform: uppercase; letter-spacing: 1px; }
    .client-period { margin-left: auto; text-align: right; }
    .client-period .period-label { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 1px; }
    .client-period .period-value { font-family: 'Anton', sans-serif; font-size: 18px; color: var(--white); margin-top: 4px; }
    .section-title { font-family: 'Anton', sans-serif; font-size: 13px; text-transform: uppercase; letter-spacing: 2px; color: #999; margin-bottom: 16px; margin-top: 36px; }
    .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
    .kpi-card { background: #fff; border: 1.5px solid var(--border); border-radius: 4px; padding: 20px 18px; }
    .kpi-card.highlight { border-color: var(--teal); background: #f0fdfd; }
    .kpi-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #999; margin-bottom: 8px; }
    .kpi-value { font-family: 'Anton', sans-serif; font-size: 28px; color: var(--dark); line-height: 1; }
    .kpi-card.highlight .kpi-value { color: var(--teal); }
    .kpi-badge { display: inline-block; margin-top: 8px; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 2px; }
    .kpi-badge.up { background: #e6faf7; color: #1a9e8f; }
    .kpi-badge.neutral { background: #f5f0e8; color: #a06020; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .posts-list { display: flex; flex-direction: column; gap: 12px; }
    .post-card { background: #fff; border: 1.5px solid var(--border); border-radius: 4px; padding: 16px 18px; display: flex; align-items: center; gap: 16px; }
    .post-rank { font-family: 'Anton', sans-serif; font-size: 22px; color: var(--cream); width: 28px; flex-shrink: 0; }
    .post-thumb { width: 44px; height: 44px; border-radius: 3px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .post-info { flex: 1; }
    .post-caption { font-size: 12px; font-weight: 700; color: var(--dark); margin-bottom: 4px; }
    .post-type { font-size: 10px; color: #aaa; text-transform: uppercase; letter-spacing: 1px; }
    .post-stats { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
    .post-stat { font-size: 11px; font-weight: 700; }
    .post-stat span { font-size: 10px; font-weight: 400; color: #aaa; }
    .post-eng { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 2px; background: #e6faf7; color: #1a9e8f; }
    .notes-box { background: #fff8f4; border: 1.5px solid #ffe0cc; border-left: 4px solid var(--orange); border-radius: 4px; padding: 20px 24px; margin-top: 14px; }
    .notes-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--orange); margin-bottom: 12px; font-weight: 700; }
    .notes-item { font-size: 12px; color: var(--dark); margin-bottom: 10px; padding-left: 16px; position: relative; line-height: 1.6; }
    .notes-item::before { content: '→'; position: absolute; left: 0; color: var(--orange); }
    .notes-item:last-child { margin-bottom: 0; }
    .print-btn { display: block; margin: 32px auto 0; padding: 12px 32px; background: var(--dark); color: var(--white); border: none; border-radius: 4px; font-family: 'Anton', sans-serif; font-size: 14px; letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer; }
    .print-btn:hover { background: #333; }
    footer { background: var(--dark); margin-top: 60px; padding: 28px 48px; display: flex; align-items: center; justify-content: space-between; }
    .footer-dev { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #555; }
    .footer-logo img { height: 36px; width: auto; display: block; }
    .footer-right { font-size: 10px; color: #444; text-align: right; }
    @media print {
      .print-btn { display: none; }
      body { background: #fff; }
    }
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
      <div class="client-avatar">{$cliente[0]}{$cliente[1]}</div>
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
        <div class="kpi-value">{$seguidores}</div>
        <span class="kpi-badge up">{$seg_var} · {$seg_pct}</span>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Alcance total</div>
        <div class="kpi-value">{$alcance}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Impressões</div>
        <div class="kpi-value">{$impressoes}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Eng. médio</div>
        <div class="kpi-value">{$engajamento}</div>
      </div>
    </div>
    <div class="kpi-grid" style="margin-top:14px;">
      <div class="kpi-card">
        <div class="kpi-label">Posts</div>
        <div class="kpi-value">{$posts}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Visitas ao perfil</div>
        <div class="kpi-value">{$visitas}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Cliques no link</div>
        <div class="kpi-value">{$cliques}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Views Stories</div>
        <div class="kpi-value">{$stories}</div>
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
    <div class="footer-right">
      {$periodo}<br>
      <span style="color:#333;">agenciamoa.com.br</span>
    </div>
  </footer>
</body>
</html>
HTML;
