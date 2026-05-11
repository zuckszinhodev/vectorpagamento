<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Verifique seu e-mail</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 40px; }
        .btn { display: inline-block; background: #6366f1; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 6px; font-weight: bold; margin: 20px 0; }
        .footer { margin-top: 30px; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <h2>OlÃ¡, {{ $userName }}!</h2>
        <p>Obrigado por se cadastrar na Vector Pagamentos. Para completar seu registro, confirme seu e-mail clicando no botÃ£o abaixo:</p>
        <p style="text-align:center">
            <a href="{{ $verificationUrl }}" class="btn">Verificar E-mail</a>
        </p>
        <p>Se vocÃª nÃ£o criou uma conta na Vector Pagamentos, ignore este e-mail.</p>
        <p>Este link expira em <strong>24 horas</strong>.</p>
        <div class="footer">
            <p>Se o botÃ£o nÃ£o funcionar, copie e cole o link abaixo no seu navegador:</p>
            <p>{{ $verificationUrl }}</p>
        </div>
    </div>
</body>
</html>
