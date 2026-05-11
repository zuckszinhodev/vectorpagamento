<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Checkout;
use Illuminate\Support\Facades\Log;

/**
 * Service to render a complete, self-contained HTML checkout page
 * server-side from checkout settings stored in the database.
 */
class CheckoutRendererService
{
    /**
     * Render a full HTML checkout page from a Checkout model.
     */
    public function renderToHtml(Checkout $checkout): string
    {
        $checkout->load('product');

        $settings = $checkout->settings ?? [];
        $theme    = $checkout->theme ?? $settings['theme'] ?? $this->defaultTheme();
        $product  = $this->buildProductData($checkout);
        $payment  = $settings['payment'] ?? $this->defaultPayment();
        $conversion = $settings['conversion'] ?? [];
        $blocks   = $settings['blocks'] ?? $this->defaultBlocks();

        $apiBase  = rtrim(config('app.url', 'http://127.0.0.1:8000'), '/');
        $slug     = $checkout->slug;

        $borderRadius   = $this->getBorderRadius($theme['borderRadius'] ?? 'xl');
        $buttonBg       = $this->getButtonBackground($theme);
        $buttonBorder   = ($theme['buttonStyle'] ?? 'gradient') === 'outline'
            ? "2px solid {$theme['primaryColor']}" : 'none';
        $buttonColor    = ($theme['buttonStyle'] ?? 'gradient') === 'outline'
            ? $theme['primaryColor'] : '#ffffff';
        $primaryColor   = $theme['primaryColor'] ?? '#6366f1';
        $secondaryColor = $theme['secondaryColor'] ?? '#8b5cf6';
        $textColor      = $theme['textColor'] ?? '#1f2937';
        $mutedColor     = $theme['mutedColor'] ?? '#6b7280';
        $cardBg         = $theme['cardBackground'] ?? '#ffffff';
        $bgColor        = $theme['backgroundColor'] ?? '#f8fafc';
        $fontFamily     = $theme['fontFamily'] ?? 'Inter';
        $shadow         = $this->getShadow($theme['shadow'] ?? 'lg');

        // Filter enabled blocks
        $enabledBlocks = array_filter($blocks, fn ($b) => !empty($b['enabled']));
        $enabledBlocks = array_values($enabledBlocks);

        // Render each block
        $headerHtml = '';
        $bodyHtml   = '';
        foreach ($enabledBlocks as $block) {
            $type = $block['type'] ?? '';
            $bs   = $block['settings'] ?? [];

            if ($type === 'header') {
                $headerHtml .= $this->renderHeader($bs, $primaryColor);
                continue;
            }

            $html = match ($type) {
                'timer'        => $this->renderTimer($bs, $conversion, $primaryColor, $mutedColor, $borderRadius),
                'product'      => $this->renderProduct($bs, $product, $textColor, $mutedColor, $primaryColor, $borderRadius),
                'benefits'     => $this->renderBenefits($bs, $textColor, $primaryColor),
                'payment'      => $this->renderPayment($bs, $payment, $product, $theme, $borderRadius, $buttonBg, $buttonBorder, $buttonColor, $slug, $apiBase),
                'testimonials' => $this->renderTestimonials($bs, $conversion, $textColor, $primaryColor),
                'faq'          => $this->renderFaq($bs, $textColor, $mutedColor, $primaryColor, $borderRadius),
                'bonus'        => $this->renderBonus($bs, $primaryColor, $borderRadius),
                'guarantee'    => $this->renderGuarantee($bs, $conversion, $primaryColor),
                'urgency'      => $this->renderUrgency($bs, $borderRadius),
                'video'        => $this->renderVideo($bs, $borderRadius),
                'trust_badges' => $this->renderTrustBadges($primaryColor),
                'shipping'     => $this->renderShipping($bs, $textColor, $mutedColor, $primaryColor, $borderRadius, $buttonBg, $buttonColor, $product),
                'social_proof' => '', // handled separately at the end
                default        => '',
            };
            $bodyHtml .= $html;
        }

        // Social proof
        $socialProofHtml = '';
        $spBlock = null;
        foreach ($enabledBlocks as $b) {
            if (($b['type'] ?? '') === 'social_proof' && !empty($b['settings']['enabled'])) {
                $spBlock = $b;
                break;
            }
        }
        if ($spBlock) {
            $socialProofHtml = $this->renderSocialProof($spBlock['settings'], $product, $primaryColor);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout - {$this->e($product['name'])}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family={$fontFamily}:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'{$fontFamily}',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:{$bgColor};color:{$textColor};line-height:1.5}
.container{width:100%;max-width:600px;margin:0 auto;background:{$cardBg};min-height:100vh;border-radius:{$borderRadius};box-shadow:{$shadow}}
.header{display:flex;align-items:center;justify-content:space-between;padding:1rem 2rem;border-bottom:1px solid {$primaryColor}15;width:100%;border-radius:{$borderRadius} {$borderRadius} 0 0}
.content-wrapper{max-width:600px;margin:0 auto;padding:0 1.5rem 1.5rem}
.logo{font-size:1.25rem;font-weight:bold;color:{$primaryColor}}
.badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;font-weight:500;color:#059669;background:#f0fdf4;padding:.35rem .75rem;border-radius:9999px}
.section{padding:1.25rem 0}
.timer{margin:1rem 0;padding:1rem;background:{$primaryColor}10;border-radius:{$borderRadius};text-align:center}
.timer-message{color:{$mutedColor};font-size:.875rem;font-weight:500;margin-bottom:.25rem}
.timer-count{color:{$primaryColor};font-size:2rem;font-weight:bold;letter-spacing:.05em}
.product-img{width:100%;max-width:600px;margin:0 auto 1rem;display:block;aspect-ratio:16/9;border-radius:{$borderRadius};object-fit:cover}
.product-title{font-size:1.5rem;font-weight:bold;color:{$textColor};line-height:1.2}
.product-desc{color:{$mutedColor};font-size:.875rem;margin-top:.75rem;line-height:1.6}
.price-container{display:flex;align-items:center;gap:.75rem;margin-top:1rem}
.price-old{text-decoration:line-through;color:{$mutedColor};font-size:1.125rem}
.price{color:{$primaryColor};font-size:1.875rem;font-weight:bold}
.benefits{list-style:none;margin:0;padding:0}
.benefit-item{display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;font-size:.875rem;color:{$textColor}}
.check-icon{width:1.25rem;height:1.25rem;flex-shrink:0}
.input{width:100%;padding:.75rem;border:1px solid #e2e8f0;border-radius:{$borderRadius};font-size:.875rem;margin-bottom:.75rem;font-family:inherit;color:{$textColor};background:#ffffff}
.input::placeholder{color:#9ca3af}
.input:focus{outline:none;border-color:{$primaryColor};box-shadow:0 0 0 3px {$primaryColor}20}
.payment-methods{display:flex;gap:.5rem;margin:.5rem 0 .75rem}
.payment-btn{flex:1;padding:.75rem;border:2px solid #e2e8f0;background:transparent;border-radius:{$borderRadius};cursor:pointer;font-size:.875rem;font-weight:500;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:.5rem;font-family:inherit;color:{$textColor}}
.payment-btn:hover{border-color:{$primaryColor}}
.payment-btn.active{border-color:{$primaryColor};color:{$primaryColor};background:{$primaryColor}10}
.submit-btn{width:100%;padding:1rem;background:{$buttonBg};color:{$buttonColor};border:{$buttonBorder};border-radius:{$borderRadius};font-size:1.125rem;font-weight:bold;cursor:pointer;margin-top:1rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:opacity .2s;font-family:inherit}
.submit-btn:hover{opacity:.9}
.submit-btn:disabled{opacity:.6;cursor:not-allowed}
.testimonial{background:#f9fafb;padding:1rem;border-radius:{$borderRadius};margin-bottom:.75rem}
.testimonial-header{display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem}
.avatar{width:2.5rem;height:2.5rem;border-radius:9999px;background:linear-gradient(to bottom right,#a855f7,#ec4899);display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:1rem;flex-shrink:0}
.avatar-img{width:2.5rem;height:2.5rem;border-radius:9999px;object-fit:cover}
.stars{color:#fbbf24;display:flex;gap:.125rem}
.testimonial-name{font-weight:500;color:{$textColor};font-size:.875rem}
.testimonial-text{font-size:.875rem;color:{$mutedColor};line-height:1.5}
.faq-item{background:#f9fafb;padding:1rem;border-radius:{$borderRadius};margin-bottom:.75rem}
.faq-question{font-weight:500;margin-bottom:.5rem;color:{$textColor}}
.faq-answer{color:{$mutedColor};font-size:.875rem;line-height:1.5}
.bonus-container{margin:0 0 1rem;padding:1rem;background:{$primaryColor}08;border:1px dashed {$primaryColor}40;border-radius:{$borderRadius}}
.bonus-title{font-weight:bold;color:{$primaryColor};margin-bottom:.75rem;font-size:1rem}
.bonus-item{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;font-size:.875rem}
.bonus-name{display:flex;align-items:center;gap:.5rem;color:{$textColor}}
.bonus-value{color:{$mutedColor};font-weight:500;text-decoration:line-through}
.bonus-footer{margin-top:.75rem;text-align:center;font-size:.75rem;font-weight:500;color:{$primaryColor}}
.guarantee-container{padding:1.25rem;text-align:center;border-top:1px solid {$primaryColor}15}
.guarantee-badge{display:inline-flex;align-items:center;gap:.75rem;padding:1rem 1.5rem;background:#f0fdf4;border-radius:1rem}
.guarantee-icon{width:2rem;height:2rem;background:#10b981;border-radius:9999px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.guarantee-icon svg{width:2rem;height:2rem;color:white}
.guarantee-content{text-align:left}
.guarantee-title{font-weight:bold;color:#065f46;font-size:.875rem}
.guarantee-days{font-size:.75rem;color:#059669}
.guarantee-desc{font-size:.75rem;color:#059669}
.urgency-banner{margin:0 0 1rem;padding:.75rem 1rem;border-radius:{$borderRadius};text-align:center}
.urgency-text{font-weight:600;font-size:.875rem}
.video-container{padding:1.25rem 0}
.video-wrapper{position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:{$borderRadius}}
.video-wrapper iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:0}
.section-title{font-weight:600;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.border-top{border-top:1px solid {$primaryColor}15}
.trust-badges{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:1.5rem;padding:1.25rem;border-top:1px solid {$primaryColor}15}
.trust-badge-item{display:flex;align-items:center;gap:.375rem;font-size:.75rem;color:#6b7280}
.trust-badge-icon{width:1.25rem;height:1.25rem}
.card-fields{display:none}
.card-fields.show{display:block}
.pix-fields{display:none}
.pix-fields.show{display:block}
.pix-qr{text-align:center;padding:1.5rem}
.pix-qr img{max-width:250px;margin:0 auto}
.pix-code{background:#f3f4f6;border-radius:.5rem;padding:.75rem;margin-top:1rem;word-break:break-all;font-size:.75rem;color:#374151;cursor:pointer}
.pix-code:hover{background:#e5e7eb}
.result-container{display:none;text-align:center;padding:2rem}
.result-container.show{display:block}
.result-success{color:#059669}
.result-error{color:#ef4444}
.spinner{display:inline-block;width:1.25rem;height:1.25rem;border:2px solid rgba(255,255,255,.3);border-radius:50%;border-top-color:#fff;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.social-proof-notification{position:fixed;z-index:9999;background:white;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.15);padding:16px;max-width:320px;border:1px solid #e5e7eb;opacity:0;transform:translateY(20px);transition:all .4s ease}
.social-proof-notification.show{opacity:1;transform:translateY(0)}
.social-proof-notification.bottom-left{bottom:20px;left:20px}
.social-proof-notification.bottom-right{bottom:20px;right:20px}
.social-proof-notification.top-left{top:20px;left:20px}
.social-proof-notification.top-right{top:20px;right:20px}
.social-proof-content{display:flex;align-items:flex-start}
.social-proof-text{flex:1}
.social-proof-text p{font-size:14px;color:#374151;margin:0;line-height:1.4}
.social-proof-text .sp-n{font-weight:600;color:#111827}
.social-proof-text .sp-p{color:{$primaryColor};font-weight:500}
.social-proof-text .sp-m{color:#6b7280}
.social-proof-time{font-size:12px;color:#9ca3af;margin-top:4px}
.social-proof-close{position:absolute;top:8px;right:8px;background:none;border:none;color:#9ca3af;cursor:pointer;padding:4px;font-size:16px;line-height:1}
.social-proof-close:hover{color:#6b7280}
@media(min-width:640px){.container{min-height:auto;margin-top:2rem;margin-bottom:2rem}}
</style>
</head>
<body>
<div class="container">
{$headerHtml}
<div class="content-wrapper">
{$bodyHtml}
</div>
</div>
{$socialProofHtml}
<script>
const API_BASE="{$apiBase}";
const SLUG="{$this->e($slug)}";

// ---- Payment method switching ----
let activeMethod='credit_card';
document.querySelectorAll('.pm-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.pm-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    activeMethod=btn.dataset.method;
    document.querySelectorAll('.card-fields').forEach(el=>el.classList.toggle('show',activeMethod==='credit_card'));
    document.querySelectorAll('.pix-fields,.boleto-fields').forEach(el=>el.classList.remove('show'));
  });
});

// ---- Timer countdown ----
(function(){
  const el=document.getElementById('timer-display');
  if(!el) return;
  let total=parseInt(el.dataset.seconds||'0',10);
  if(total<=0) return;
  function tick(){
    if(total<=0){el.textContent='00:00:00';return;}
    total--;
    const h=Math.floor(total/3600),m=Math.floor((total%3600)/60),s=total%60;
    el.textContent=String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  }
  setInterval(tick,1000);
})();

// ---- Form submission ----
const form=document.getElementById('checkout-form');
if(form){
  form.addEventListener('submit',async function(e){
    e.preventDefault();
    const btn=form.querySelector('.submit-btn');
    const origText=btn.innerHTML;
    btn.disabled=true;
    btn.innerHTML='<span class="spinner"></span> Processando...';

    const body={
      payment_method: activeMethod,
      customer:{
        name: form.querySelector('[name=name]')?.value||'',
        email: form.querySelector('[name=email]')?.value||'',
        document: form.querySelector('[name=document]')?.value||'',
        phone: form.querySelector('[name=phone]')?.value||'',
      },
    };
    if(activeMethod==='credit_card'){
      body.card={
        number: form.querySelector('[name=card_number]')?.value||'',
        expiry: form.querySelector('[name=card_expiry]')?.value||'',
        cvv: form.querySelector('[name=card_cvv]')?.value||'',
        installments: parseInt(form.querySelector('[name=installments]')?.value||'1',10),
      };
    }

    try{
      const res=await fetch(API_BASE+'/api/v1/checkout/'+SLUG+'/process',{
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify(body),
      });
      const data=await res.json();
      if(data.success){
        if(activeMethod==='pix' && data.data?.pix){
          // Show PIX QR
          const pixDiv=document.getElementById('pix-result');
          if(pixDiv){
            let qrHtml='<h3 style="margin-bottom:1rem;font-size:1.25rem">Pague com PIX</h3>';
            if(data.data.pix.qr_code_url){
              qrHtml+='<img src="'+data.data.pix.qr_code_url+'" alt="QR Code PIX" style="max-width:250px;margin:0 auto;display:block" />';
            }
            if(data.data.pix.qr_code){
              qrHtml+='<div class="pix-code" onclick="navigator.clipboard.writeText(this.textContent.trim())">'+data.data.pix.qr_code+'</div>';
              qrHtml+='<p style="font-size:.75rem;color:#6b7280;margin-top:.5rem">Clique para copiar o c\u00F3digo</p>';
            }
            pixDiv.innerHTML=qrHtml;
            pixDiv.classList.add('show');
            form.style.display='none';
          }
        } else {
          const resultDiv=document.getElementById('result-success');
          if(resultDiv){
            resultDiv.innerHTML='<h2 style="color:#059669;font-size:1.5rem;margin-bottom:.5rem">\u2705 Compra realizada!</h2><p style="color:#6b7280">'+( data.message||'Pagamento processado com sucesso.')+'</p>';
            resultDiv.classList.add('show');
            form.style.display='none';
          }
        }
      } else {
        alert(data.message||'Erro ao processar pagamento. Tente novamente.');
        btn.disabled=false;
        btn.innerHTML=origText;
      }
    }catch(err){
      alert('Erro de conex\u00E3o. Verifique sua internet e tente novamente.');
      btn.disabled=false;
      btn.innerHTML=origText;
    }
  });
}
</script>
</body>
</html>
HTML;
    }

    /* =========================================================
     *  Block Renderers
     * ========================================================= */

    private function renderHeader(array $bs, string $primaryColor): string
    {
        $logo = !empty($bs['logoUrl'])
            ? '<img src="' . $this->e($bs['logoUrl']) . '" alt="Logo" style="height:32px;max-width:150px;object-fit:contain" />'
            : '<div class="logo">' . $this->e($bs['title'] ?? 'Vector Pagamentos') . '</div>';

        $badge = !empty($bs['showSecureBadge'])
            ? '<span class="badge"><svg style="width:1rem;height:1rem" fill="none" stroke="#059669" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg> Compra Segura</span>'
            : '';

        return "<div class=\"header\">{$logo}{$badge}</div>";
    }

    private function renderTimer(array $bs, array $conversion, string $primary, string $muted, string $br): string
    {
        if (empty($conversion['timerEnabled'])) return '';

        $hours   = (int)($bs['hours'] ?? 0);
        $minutes = (int)($bs['minutes'] ?? $conversion['timerMinutes'] ?? 15);
        $seconds = (int)($bs['seconds'] ?? 0);
        $totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;
        $display = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        $msg = $this->e($bs['message'] ?? $conversion['timerMessage'] ?? 'Oferta expira em:');

        return <<<HTML
<div class="timer">
  <div class="timer-message">{$msg}</div>
  <div class="timer-count" id="timer-display" data-seconds="{$totalSeconds}">{$display}</div>
</div>
HTML;
    }

    private function renderProduct(array $bs, array $product, string $text, string $muted, string $primary, string $br): string
    {
        $imgHtml = '';
        if (!empty($bs['showImage']) && !empty($product['image_url'])) {
            $imgHtml = '<img src="' . $this->e($product['image_url']) . '" class="product-img" alt="' . $this->e($product['name']) . '" onerror="this.style.display=\'none\'" loading="lazy" />';
        }

        $descHtml = '';
        if (!empty($bs['showDescription']) && !empty($product['description'])) {
            $descHtml = '<p class="product-desc">' . $this->e($product['description']) . '</p>';
        }

        $priceHtml = '<span class="price">' . $this->formatCurrency($product['price']) . '</span>';
        $origHtml  = '';
        if (!empty($bs['showOriginalPrice']) && !empty($product['originalPrice'])) {
            $origHtml = '<span class="price-old">' . $this->formatCurrency($product['originalPrice']) . '</span>';
        }

        return <<<HTML
<div class="section">
  {$imgHtml}
  <h1 class="product-title">{$this->e($product['name'])}</h1>
  {$descHtml}
  <div class="price-container">{$origHtml}{$priceHtml}</div>
</div>
HTML;
    }

    private function renderBenefits(array $bs, string $text, string $primary): string
    {
        $items = $bs['items'] ?? [];
        if (empty($items)) return '';

        $title = $this->e($bs['title'] ?? 'O que vocÃª recebe');
        $itemsHtml = '';
        foreach ($items as $item) {
            $t = $this->e($item['text'] ?? $item['title'] ?? '');
            $itemsHtml .= <<<HTML
<li class="benefit-item">
  <svg class="check-icon" style="color:{$primary}" fill="currentColor" viewBox="0 0 20 20">
    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
  </svg>
  <span>{$t}</span>
</li>
HTML;
        }

        return <<<HTML
<div class="section">
  <h3 class="section-title" style="color:{$text}">{$title}</h3>
  <ul class="benefits">{$itemsHtml}</ul>
</div>
HTML;
    }

    private function renderPayment(
        array $bs, array $payment, array $product, array $theme,
        string $br, string $btnBg, string $btnBorder, string $btnColor,
        string $slug, string $apiBase
    ): string {
        $methods       = $payment['methods'] ?? ['credit_card', 'pix', 'boleto'];
        $maxInst       = (int)($payment['maxInstallments'] ?? 12);
        $textColor     = $theme['textColor'] ?? '#1f2937';
        $primaryColor  = $theme['primaryColor'] ?? '#6366f1';
        $price         = $product['price'] ?? 0;

        $ccBtn = in_array('credit_card', $methods)
            ? '<button type="button" class="payment-btn pm-btn active" data-method="credit_card"><svg style="width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg> CartÃ£o</button>'
            : '';
        $pixBtn = in_array('pix', $methods)
            ? '<button type="button" class="payment-btn pm-btn" data-method="pix"><svg style="width:1.25rem;height:1.25rem" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L17.5 7.5Q15.5 9 12 11Q8.5 9 6.5 7.5Z"/><path d="M22 12L16.5 17.5Q15 15.5 13 12Q15 8.5 16.5 6.5Z"/><path d="M12 22L6.5 16.5Q8.5 15 12 13Q15.5 15 17.5 16.5Z"/><path d="M2 12L7.5 6.5Q9 8.5 11 12Q9 15.5 7.5 17.5Z"/></svg> PIX</button>'
            : '';
        $boletoBtn = in_array('boleto', $methods)
            ? '<button type="button" class="payment-btn pm-btn" data-method="boleto"><svg style="width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg> Boleto</button>'
            : '';

        // Installments options
        $installmentsHtml = '';
        if (!empty($bs['showInstallments']) && $maxInst > 1) {
            $opts = '<option value="1">1x de ' . $this->formatCurrency($price) . ' sem juros</option>';
            for ($n = 2; $n <= $maxInst; $n++) {
                $val = $this->formatCurrency((int)round($price / $n));
                $opts .= "<option value=\"{$n}\">{$n}x de {$val} sem juros</option>";
            }
            $installmentsHtml = '<select class="input" name="installments" style="color:#000;margin-bottom:0">' . $opts . '</select>';
        }

        // Determine first active method for initial display
        $firstMethod = $methods[0] ?? 'credit_card';
        $cardShow = $firstMethod === 'credit_card' ? ' show' : '';

        return <<<HTML
<div class="section border-top">
  <h3 class="section-title" style="color:{$textColor}">
    <svg style="width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>
    Dados do Pagamento
  </h3>
  <form id="checkout-form">
    <input type="text" class="input" name="name" placeholder="Nome completo" required />
    <input type="email" class="input" name="email" placeholder="E-mail" required />
    <input type="text" class="input" name="document" placeholder="CPF" required />
    <input type="tel" class="input" name="phone" placeholder="WhatsApp (opcional)" />

    <div class="payment-methods">{$ccBtn}{$pixBtn}{$boletoBtn}</div>

    <div class="card-fields{$cardShow}">
      <input type="text" class="input" name="card_number" placeholder="NÃºmero do cartÃ£o" />
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <input type="text" class="input" name="card_expiry" placeholder="MM/AA" style="margin-bottom:0" />
        <input type="text" class="input" name="card_cvv" placeholder="CVV" style="margin-bottom:0" />
      </div>
      {$installmentsHtml}
    </div>

    <div id="pix-result" class="pix-fields"></div>
    <div id="result-success" class="result-container"></div>

    <button type="submit" class="submit-btn">
      <svg style="width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
      FINALIZAR COMPRA
    </button>
    <p style="text-align:center;font-size:.75rem;color:#6b7280;margin-top:.5rem">Seus dados estÃ£o protegidos com criptografia SSL</p>
  </form>
</div>
HTML;
    }

    private function renderTestimonials(array $bs, array $conversion, string $text, string $primary): string
    {
        if (empty($conversion['testimonialsEnabled'])) return '';

        $items = $bs['items'] ?? [];
        $count = (int)($bs['count'] ?? 3);
        $items = array_slice($items, 0, $count);
        if (empty($items)) return '';

        $starSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fbbf24" style="width:1rem;height:1rem"><path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z" clip-rule="evenodd"/></svg>';

        $html = '';
        foreach ($items as $item) {
            $name   = $this->e($item['name'] ?? 'Cliente');
            $txt    = $this->e($item['text'] ?? '');
            $rating = (int)($item['rating'] ?? 5);
            $stars  = str_repeat($starSvg, $rating);

            $avatarHtml = !empty($item['avatar'])
                ? '<img src="' . $this->e($item['avatar']) . '" alt="' . $name . '" class="avatar-img" />'
                : '<div class="avatar">' . mb_substr($name, 0, 1) . '</div>';

            $html .= <<<HTML
<div class="testimonial">
  <div class="testimonial-header">
    {$avatarHtml}
    <div>
      <p class="testimonial-name">{$name}</p>
      <div class="stars">{$stars}</div>
    </div>
  </div>
  <p class="testimonial-text">"{$txt}"</p>
</div>
HTML;
        }

        return <<<HTML
<div class="section border-top">
  <h3 class="section-title" style="color:{$text}">
    <svg style="width:1.25rem;height:1.25rem" fill="none" stroke="{$text}" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>
    O que dizem nossos clientes
  </h3>
  {$html}
</div>
HTML;
    }

    private function renderFaq(array $bs, string $text, string $muted, string $primary, string $br): string
    {
        $items = $bs['items'] ?? [];
        if (empty($items)) return '';

        $title = $this->e($bs['title'] ?? 'Perguntas Frequentes');
        $html  = '';
        foreach ($items as $item) {
            $q = $this->e($item['question'] ?? '');
            $a = $this->e($item['answer'] ?? '');
            $html .= "<div class=\"faq-item\"><p class=\"faq-question\">{$q}</p><p class=\"faq-answer\">{$a}</p></div>";
        }

        return <<<HTML
<div class="section border-top">
  <h3 class="section-title" style="color:{$text}">
    <svg style="width:1.25rem;height:1.25rem" fill="none" stroke="{$primary}" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/></svg>
    {$title}
  </h3>
  {$html}
</div>
HTML;
    }

    private function renderBonus(array $bs, string $primary, string $br): string
    {
        $items = $bs['items'] ?? [];
        if (empty($items)) return '';

        $title = $this->e($bs['title'] ?? 'BÃ´nus Exclusivos');
        $html  = '';
        foreach ($items as $item) {
            $name  = $this->e($item['name'] ?? '');
            $value = $this->e($item['value'] ?? '');
            $html .= <<<HTML
<div class="bonus-item">
  <span class="bonus-name">
    <svg style="width:1.25rem;height:1.25rem;color:{$primary}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4.5 12.75 6 6 9-13.5"/></svg>
    {$name}
  </span>
  <span class="bonus-value">{$value}</span>
</div>
HTML;
        }

        return <<<HTML
<div class="bonus-container">
  <h3 class="bonus-title">{$title}</h3>
  {$html}
  <p class="bonus-footer">Tudo isso GRÃTIS hoje!</p>
</div>
HTML;
    }

    private function renderGuarantee(array $bs, array $conversion, string $primary): string
    {
        $title = $this->e($bs['title'] ?? 'Garantia Incondicional');
        $days  = (int)($bs['days'] ?? $conversion['guaranteeDays'] ?? 7);
        $desc  = $this->e($bs['description'] ?? 'Se nÃ£o gostar, devolvemos 100% do seu dinheiro');

        return <<<HTML
<div class="guarantee-container">
  <div class="guarantee-badge">
    <div class="guarantee-icon">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
    </div>
    <div class="guarantee-content">
      <p class="guarantee-title">{$title}</p>
      <p class="guarantee-days">{$days} dias de garantia</p>
      <p class="guarantee-desc">{$desc}</p>
    </div>
  </div>
</div>
HTML;
    }

    private function renderUrgency(array $bs, string $br): string
    {
        $bg   = $bs['backgroundColor'] ?? '#fef3c7';
        $tc   = $bs['textColor'] ?? '#92400e';
        $msg  = $this->e($bs['message'] ?? 'ðŸš¨ Ãšltimas vagas! Apenas 7 restantes');

        return <<<HTML
<div class="urgency-banner" style="background:{$bg}"><p class="urgency-text" style="color:{$tc}">{$msg}</p></div>
HTML;
    }

    private function renderVideo(array $bs, string $br): string
    {
        $url = $bs['videoUrl'] ?? '';
        if (empty($url)) return '';

        // Validate URL to prevent XSS via javascript: or data: URIs
        $allowedDomains = ['youtube.com', 'www.youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com', 'www.vimeo.com'];
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, $allowedDomains, true)) {
            return '';
        }

        return <<<HTML
<div class="video-container"><div class="video-wrapper"><iframe src="{$this->e($url)}" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe></div></div>
HTML;
    }

    private function renderTrustBadges(string $primary): string
    {
        return <<<HTML
<div class="trust-badges">
  <div class="trust-badge-item">
    <svg class="trust-badge-icon" style="color:#22c55e" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
    <span>Compra Segura</span>
  </div>
  <div class="trust-badge-item">
    <svg class="trust-badge-icon" style="color:#eab308" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/></svg>
    <span>Acesso Imediato</span>
  </div>
  <div class="trust-badge-item">
    <svg class="trust-badge-icon" style="color:#ef4444" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/></svg>
    <span>SatisfaÃ§Ã£o Garantida</span>
  </div>
</div>
HTML;
    }

    private function renderShipping(array $bs, string $text, string $muted, string $primary, string $br, string $btnBg, string $btnColor, array $product): string
    {
        if (($bs['type'] ?? 'digital') === 'physical') {
            $shippingPrice = !empty($bs['freeShipping']) ? 'GRÃTIS' : $this->formatCurrency($bs['shippingPrice'] ?? 0);
            return <<<HTML
<div class="section border-top">
  <h3 class="section-title" style="color:{$text}">
    <svg style="width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
    EndereÃ§o de Entrega
  </h3>
  <input type="text" class="input" name="cep" placeholder="CEP" maxlength="9" />
  <input type="text" class="input" name="street" placeholder="Rua/Avenida" />
  <div style="display:grid;grid-template-columns:120px 1fr;gap:.75rem">
    <input type="text" class="input" name="number" placeholder="NÃºmero" style="margin-bottom:0" />
    <input type="text" class="input" name="complement" placeholder="Complemento (opcional)" style="margin-bottom:0" />
  </div>
  <input type="text" class="input" name="neighborhood" placeholder="Bairro" />
  <div style="display:grid;grid-template-columns:1fr 80px;gap:.75rem">
    <input type="text" class="input" name="city" placeholder="Cidade" style="margin-bottom:0" />
    <input type="text" class="input" name="state" placeholder="UF" maxlength="2" style="margin-bottom:0;text-transform:uppercase" />
  </div>
  <div style="background:#f3f4f6;border-radius:.5rem;padding:.75rem;margin-top:.5rem">
    <p style="font-size:.875rem;color:{$text};margin:0"><strong>Frete:</strong> {$shippingPrice}</p>
  </div>
</div>
HTML;
        }

        $msg = $this->e($bs['message'] ?? 'ðŸ“§ Entrega imediata por e-mail apÃ³s confirmaÃ§Ã£o');
        return <<<HTML
<div style="margin:0 0 1rem;padding:.75rem 1rem;background:#eff6ff;border-radius:.5rem;text-align:center">
  <p style="font-size:.875rem;color:#1d4ed8">{$msg}</p>
</div>
HTML;
    }

    private function renderSocialProof(array $bs, array $product, string $primary): string
    {
        if (empty($bs['enabled'])) return '';

        $names          = json_encode($bs['names'] ?? ['JoÃ£o Silva', 'Maria Santos'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $paymentMethods = json_encode($bs['paymentMethods'] ?? ['PIX', 'CartÃ£o de CrÃ©dito'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $position       = $this->e($bs['position'] ?? 'bottom-left');
        $displayTime    = ((int)($bs['displayTime'] ?? 5)) * 1000;
        $intervalTime   = ((int)($bs['intervalTime'] ?? 8)) * 1000;
        $showPM         = !empty($bs['showPaymentMethod']) ? 'true' : 'false';
        $pName          = $this->e($product['name'] ?? 'este produto');

        $pmSpan = '<span class="sp-m" id="sp-payment"> com PIX</span>';

        return <<<HTML
<div id="social-proof-notification" class="social-proof-notification {$position}">
  <button class="social-proof-close" onclick="document.getElementById('social-proof-notification').classList.remove('show')">&times;</button>
  <div class="social-proof-content">
    <div class="social-proof-text">
      <p><span class="sp-n" id="sp-name">JoÃ£o Silva</span> comprou <span class="sp-p">{$pName}</span>{$pmSpan}</p>
      <div class="social-proof-time" id="sp-time">Agora mesmo</div>
    </div>
  </div>
</div>
<script>
(function(){
  var names={$names},pms={$paymentMethods},dt={$displayTime},it={$intervalTime},showPM={$showPM};
  var el=document.getElementById('social-proof-notification'),
      ne=document.getElementById('sp-name'),pe=document.getElementById('sp-payment'),
      te=document.getElementById('sp-time');
  var times=['Agora mesmo','HÃ¡ 1 minuto','HÃ¡ 2 minutos','HÃ¡ 3 minutos','HÃ¡ 5 minutos'];
  function r(a){return a[Math.floor(Math.random()*a.length)];}
  function show(){
    ne.textContent=r(names);
    if(pe&&showPM) pe.textContent=' com '+r(pms);
    te.textContent=r(times);
    el.classList.add('show');
    setTimeout(function(){el.classList.remove('show');},dt);
  }
  setTimeout(show,3000);
  setInterval(function(){if(!el.classList.contains('show'))show();},it+dt);
})();
</script>
HTML;
    }

    /* =========================================================
     *  Helpers
     * ========================================================= */

    private function buildProductData(Checkout $checkout): array
    {
        $product = $checkout->product;
        $settingsProduct = $checkout->settings['product'] ?? [];

        $imageUrl = $product?->image_url ?? $settingsProduct['image_url'] ?? null;

        // Sanitize broken image URLs (e.g. old Pollinations.ai URLs)
        if ($imageUrl && $this->isUnreliableImageUrl($imageUrl)) {
            $imageUrl = $this->buildFallbackImageUrl($product?->name ?? $settingsProduct['name'] ?? 'produto');
        }

        // Live product data from DB takes priority over settings snapshot
        return [
            'name'          => $product?->name ?? $settingsProduct['name'] ?? 'Produto',
            'description'   => $product?->description ?? $settingsProduct['description'] ?? '',
            'price'         => $product?->price ?? $settingsProduct['price'] ?? 0,
            'originalPrice' => $product?->original_price ?? $settingsProduct['originalPrice'] ?? null,
            'image_url'     => $imageUrl,
            'type'          => $product?->type ?? $settingsProduct['type'] ?? 'digital',
        ];
    }

    /**
     * Check if an image URL points to an unreliable/broken service
     */
    private function isUnreliableImageUrl(string $url): bool
    {
        $unreliableHosts = [
            'image.pollinations.ai',
            'pollinations.ai',
        ];

        foreach ($unreliableHosts as $host) {
            if (str_contains($url, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a fallback image URL from product name keywords
     */
    private function buildFallbackImageUrl(string $productName): string
    {
        $words = preg_split('/[\s,.:;!?]+/', strtolower(trim($productName)));
        $stopWords = ['de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'um', 'uma', 'para', 'com', 'por', 'o', 'a', 'os', 'as', 'e'];
        $keywords = [];
        foreach ($words as $w) {
            if (strlen($w) > 2 && !in_array($w, $stopWords)) {
                $keywords[] = $w;
            }
        }
        $seed = abs(crc32($productName)) % 100000;
        if (!empty($keywords)) {
            $kw = implode(',', array_slice($keywords, 0, 3));
            return "https://loremflickr.com/800/450/{$kw}?lock={$seed}";
        }
        return "https://picsum.photos/seed/{$seed}/800/450";
    }

    private function formatCurrency(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    private function getBorderRadius(string $size): string
    {
        return match ($size) {
            'none' => '0',
            'sm'   => '0.25rem',
            'md'   => '0.375rem',
            'lg'   => '0.5rem',
            'xl'   => '0.75rem',
            '2xl'  => '1rem',
            'full' => '9999px',
            default => '0.75rem',
        };
    }

    private function getButtonBackground(array $theme): string
    {
        return match ($theme['buttonStyle'] ?? 'gradient') {
            'solid'   => $theme['primaryColor'] ?? '#6366f1',
            'outline' => 'transparent',
            default   => 'linear-gradient(135deg, ' . ($theme['primaryColor'] ?? '#6366f1') . ', ' . ($theme['secondaryColor'] ?? '#8b5cf6') . ')',
        };
    }

    private function getShadow(string $size): string
    {
        return match ($size) {
            'none' => 'none',
            'sm'   => '0 1px 2px 0 rgba(0,0,0,.05)',
            'md'   => '0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -2px rgba(0,0,0,.1)',
            'lg'   => '0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1)',
            'xl'   => '0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1)',
            '2xl'  => '0 25px 50px -12px rgba(0,0,0,.25)',
            default => '0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1)',
        };
    }

    private function defaultTheme(): array
    {
        return [
            'primaryColor'    => '#8b5cf6',
            'secondaryColor'  => '#6366f1',
            'backgroundColor' => '#f8fafc',
            'cardBackground'  => '#ffffff',
            'textColor'       => '#1e293b',
            'mutedColor'      => '#64748b',
            'borderRadius'    => 'xl',
            'fontFamily'      => 'Inter',
            'buttonStyle'     => 'gradient',
            'shadow'          => 'lg',
        ];
    }

    private function defaultPayment(): array
    {
        return [
            'methods'         => ['credit_card', 'pix', 'boleto'],
            'maxInstallments' => 12,
            'pixDiscount'     => 0,
            'boletoDiscount'  => 0,
        ];
    }

    private function defaultBlocks(): array
    {
        return [
            ['type' => 'header',       'enabled' => true, 'settings' => ['title' => 'Vector Pagamentos', 'showSecureBadge' => true]],
            ['type' => 'product',      'enabled' => true, 'settings' => ['showImage' => true, 'showDescription' => true, 'showOriginalPrice' => true]],
            ['type' => 'payment',      'enabled' => true, 'settings' => ['showInstallments' => true]],
            ['type' => 'trust_badges', 'enabled' => true, 'settings' => []],
            ['type' => 'guarantee',    'enabled' => true, 'settings' => ['title' => 'Garantia Incondicional', 'days' => 7, 'description' => 'Se nÃ£o gostar, devolvemos 100% do seu dinheiro']],
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
