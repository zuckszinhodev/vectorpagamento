# Como Testar o SEO do Vector Pagamentos

## ✅ Configuração Atual

O sistema está configurado para usar o domínio: **app.vectorpagamentos.com**

Banner social: `/img/bannersocial.png` (1200x630px)

## 🧪 Testando as Meta Tags

### 1. Facebook/Instagram/WhatsApp

**URL de Teste:** https://developers.facebook.com/tools/debug/

**Passos:**
1. Acesse o link acima
2. Cole a URL: `https://app.vectorpagamentos.com`
3. Clique em "Fetch new information" ou "Depurar"
4. Verifique se o banner aparece corretamente

**O que você deve ver:**
- ✅ Título: "Vector Pagamentos - Gateway de Pagamento Completo"
- ✅ Descrição: "Plataforma completa de pagamentos..."
- ✅ Imagem: Banner social (1200x630px)

**Importante:** Se a imagem não aparecer na primeira vez:
- Clique em "Scrape Again" ou "Buscar novamente"
- O Facebook faz cache, pode levar alguns minutos

### 2. Twitter/X

**URL de Teste:** https://cards-dev.twitter.com/validator

**Passos:**
1. Acesse o link acima
2. Cole a URL: `https://app.vectorpagamentos.com`
3. Clique em "Preview card"

**O que você deve ver:**
- ✅ Card tipo "Summary Large Image"
- ✅ Banner social em destaque
- ✅ Título e descrição

### 3. LinkedIn

**URL de Teste:** https://www.linkedin.com/post-inspector/

**Passos:**
1. Acesse o link acima
2. Cole a URL: `https://app.vectorpagamentos.com`
3. Clique em "Inspect"

**O que você deve ver:**
- ✅ Preview do post com banner
- ✅ Título e descrição formatados

### 4. WhatsApp (Teste Real)

**Passos:**
1. Abra o WhatsApp Web ou App
2. Envie a URL para você mesmo: `https://app.vectorpagamentos.com`
3. Aguarde o preview carregar

**O que você deve ver:**
- ✅ Banner social aparece como thumbnail
- ✅ Título e descrição abaixo

## 🔍 Verificando o Banner Social

### Requisitos do Banner:
- ✅ Dimensões: 1200x630px (proporção 1.91:1)
- ✅ Formato: PNG ou JPG
- ✅ Tamanho: < 8MB (recomendado < 1MB)
- ✅ Localização: `/frontend/public/img/bannersocial.png`

### Como verificar se o banner está correto:

**No navegador:**
```
https://app.vectorpagamentos.com/img/bannersocial.png
```

**Localmente:**
```
http://localhost:5173/img/bannersocial.png
```

## 🚀 Testando Localmente

### 1. Iniciar o servidor de desenvolvimento:
```bash
cd frontend
npm run dev
```

### 2. Testar meta tags localmente:

Instale a extensão do navegador:
- Chrome: "Meta SEO Inspector"
- Firefox: "SEO Meta in 1 Click"

### 3. Verificar no código-fonte:

Abra `http://localhost:5173` e pressione `Ctrl+U` (ou `Cmd+U` no Mac)

Procure por:
```html
<meta property="og:image" content="https://app.vectorpagamentos.com/img/bannersocial.png" />
```

## 📱 Testando em Diferentes Redes Sociais

### Instagram Stories/Feed:
1. Poste a URL em uma story ou post
2. O Instagram vai buscar as meta tags automaticamente
3. O banner deve aparecer como preview

### Telegram:
1. Envie a URL em qualquer chat
2. O Telegram gera preview automaticamente
3. Banner aparece com título e descrição

### Discord:
1. Cole a URL em qualquer canal
2. Discord gera embed automaticamente
3. Banner aparece em tamanho grande

## 🐛 Problemas Comuns

### Banner não aparece:

**Causa 1:** Cache das redes sociais
- **Solução:** Use as ferramentas de debug para forçar atualização

**Causa 2:** Imagem muito grande
- **Solução:** Otimize o banner para < 1MB

**Causa 3:** URL incorreta
- **Solução:** Verifique se a URL está acessível publicamente

### Banner aparece cortado:

**Causa:** Dimensões incorretas
- **Solução:** Use exatamente 1200x630px

### Título/Descrição errados:

**Causa:** Meta tags não atualizadas
- **Solução:** Limpe o cache do navegador e das redes sociais

## 🎯 Checklist Final

Antes de compartilhar nas redes sociais:

- [ ] Banner está em `/frontend/public/img/bannersocial.png`
- [ ] Banner tem 1200x630px
- [ ] Banner é menor que 1MB
- [ ] Testado no Facebook Debugger
- [ ] Testado no Twitter Card Validator
- [ ] Testado no LinkedIn Post Inspector
- [ ] URL `app.vectorpagamentos.com` está acessível
- [ ] HTTPS está funcionando
- [ ] Imagem carrega sem erros

## 📊 Monitoramento

Após publicar, monitore:

1. **Google Search Console**
   - Submeta o sitemap: `https://app.vectorpagamentos.com/sitemap.xml`
   - Verifique indexação

2. **Analytics**
   - Instale Google Analytics (opcional)
   - Monitore tráfego de redes sociais

3. **Compartilhamentos**
   - Teste em todas as redes sociais principais
   - Peça feedback de usuários

## 💡 Dicas Extras

### Para melhor engajamento:

1. **Banner atrativo:**
   - Use cores vibrantes
   - Texto legível
   - Logo visível
   - Call-to-action claro

2. **Descrição otimizada:**
   - Máximo 155 caracteres
   - Inclua palavras-chave
   - Call-to-action

3. **Título impactante:**
   - Máximo 60 caracteres
   - Claro e direto
   - Inclua marca

## 🔄 Atualizando o Banner

Se precisar atualizar o banner:

1. Substitua o arquivo em `/frontend/public/img/bannersocial.png`
2. Limpe o cache das redes sociais usando as ferramentas de debug
3. Aguarde alguns minutos para propagação

## 📞 Suporte

Se o banner não aparecer após seguir todos os passos:

1. Verifique se o servidor está online
2. Teste a URL da imagem diretamente no navegador
3. Verifique os logs do servidor
4. Confirme que não há bloqueio de CORS

---

**Última atualização:** 24/02/2026
**Domínio configurado:** app.vectorpagamentos.com
