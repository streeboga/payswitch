import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './app/App'
import { auth } from './api/endpoints/auth'
import { useAuthStore } from './stores/auth'
import './lib/i18n'
import './app.css'

// Kick off the auth fetch BEFORE mounting — fire and forget.
// The store updates reactively; the router re-evaluates guards once
// isLoading flips to false (via the subscribe() in router.tsx).
auth
  .user()
  .then((user) => {
    useAuthStore.getState().setUser(user)
  })
  .catch(() => {
    useAuthStore.getState().setLoading(false)
  })

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
