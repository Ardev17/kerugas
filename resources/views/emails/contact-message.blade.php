<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>Novo contacto pelo site</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFC; font-family: Arial, Helvetica, sans-serif; color:#0F172A;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding: 32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#FFFFFF; border-radius:12px; overflow:hidden; border:1px solid #E2E8F0;">
          <tr>
            <td style="background:#0B5CFF; padding:20px 28px;">
              <span style="color:#FFFFFF; font-size:18px; font-weight:bold;">KeruGás — Nova mensagem de contacto</span>
            </td>
          </tr>
          <tr>
            <td style="padding: 28px;">
              <p style="margin:0 0 16px; font-size:14px; color:#64748B;">
                Recebida através do formulário de Contacto do site em {{ $contactMessage->created_at->format('d/m/Y H:i') }}.
              </p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                <tr>
                  <td style="padding: 6px 0; width: 110px; color:#64748B; vertical-align: top;">Nome</td>
                  <td style="padding: 6px 0; font-weight: bold;">{{ $contactMessage->name }}</td>
                </tr>
                <tr>
                  <td style="padding: 6px 0; color:#64748B; vertical-align: top;">Contacto</td>
                  <td style="padding: 6px 0; font-weight: bold;">{{ $contactMessage->contact }}</td>
                </tr>
              </table>

              <p style="margin: 20px 0 6px; font-size:13px; font-weight:bold; color:#64748B; text-transform:uppercase; letter-spacing: 0.5px;">Mensagem</p>
              <div style="padding: 14px 16px; background:#F8FAFC; border-radius:8px; font-size:14px; white-space: pre-line;">{{ $contactMessage->message }}</div>

              <p style="margin-top: 24px; font-size:12px; color:#94A3B8;">
                Pode responder diretamente para o contacto acima. Todas as mensagens recebidas ficam também guardadas no painel administrativo, em "Mensagens de contacto".
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
