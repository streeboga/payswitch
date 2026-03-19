import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './app/App'
import { auth } from './api/endpoints/auth'
import { useAuthStore } from './stores/auth'
import { i18nReady } from './lib/i18n'
import './app.css'

// Try to restore the authenticated session on page load.
// The session cookie is HttpOnly so we can't check it from JS —
// instead we always call /api/v1/user and let a 401 tell us there's no session.
auth
  .user()
  .then((user) => {
    useAuthStore.getState().setUser(user)
  })
  .catch(() => {
    useAuthStore.getState().setLoading(false)
  })

// Wait for i18n to load translations before rendering to avoid
// showing raw translation keys on initial page load.
i18nReady.then(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
})
