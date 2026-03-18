import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './app/App'
import { auth } from './api/endpoints/auth'
import { useAuthStore } from './stores/auth'
import { i18nReady } from './lib/i18n'
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

// Wait for i18n to load translations before rendering to avoid
// showing raw translation keys on initial page load.
i18nReady.then(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
})
