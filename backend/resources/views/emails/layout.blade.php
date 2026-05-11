<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', 'Vector Pagamentos')</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f7; color: #333333; line-height: 1.6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f7;">
        <tr>
            <td align="center" style="padding: 20px 10px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 30px 0 20px;">
                            <a href="{{ config('app.frontend_url', 'https://app.vectorpagamentos.com') }}" style="font-size: 28px; font-weight: 800; color: #6c5ce7; text-decoration: none; letter-spacing: -0.5px;">
                                Vector Pagamentos
                            </a>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 12px; padding: 40px 32px;">
                            @yield('content')
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding: 24px 0; font-size: 12px; color: #999999;">
                            <p style="margin: 0;">&copy; {{ date('Y') }} Vector Pagamentos. Todos os direitos reservados.</p>
                            <p style="margin: 8px 0 0;">
                                Esta e uma mensagem automatica, por favor nao responda.
                            </p>
                            <p style="margin: 8px 0 0;">
                                Vector Pagamentos Servicos de Pagamento | Brasil
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
