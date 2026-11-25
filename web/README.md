# KERUGAS Web (Frontend)

React + Vite + TypeScript + Tailwind CSS.

## Rodar localmente

```bash
npm install
cp ../../.env.example .env   # ajustar VITE_API_URL conforme necessário
npm run dev
```

## O que já está implementado

- Design system (tokens de cor em `tailwind.config.ts` / `src/constants/theme.ts`)
- Layout público (`Header`, `Footer` institucional, `PublicLayout`)
- Páginas: Home, Find Gas, Map (Leaflet, tempo real via Reverb), Station Details,
  Order Gas (fluxo em 4 passos), Order Success, Login/Register (ligados à API),
  Dashboard, painel do Operador, painel Admin completo (dashboard, analytics
  com gráficos, postos, utilizadores, comentários, denúncias, pedidos)
- Real-time (Etapa 16): `src/lib/echo.ts` (Laravel Echo + Reverb, ligação
  preguiçosa). O Mapa atualiza sozinho quando um posto muda de estado; o
  Admin recebe pedidos novos sem recarregar a página (canal privado)
- Code-splitting: Mapa e todo o painel Admin carregados sob demanda (`React.lazy`)
- Tracking de analytics (secção 102), fire-and-forget
- Hook `useGeolocation` com fluxo de consentimento
- Serviços de API tipados para cada domínio
- React Query para cache/estado de servidor
- 15 testes reais (Vitest),`npm run test`

## Próximas etapas (frontend)

- Zustand store de sessão do utilizador (já existe `authStore`, falta expandir)
- Dashboard de utilizador completo (favoritos/alertas listados, hoje só placeholders)
- Dark mode no painel admin
- Auditoria de acessibilidade real (Lighthouse/axe)
