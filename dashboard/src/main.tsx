import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './app/App'
import { auth } from './api/endpoints/auth'
import { useAuthStore } from './stores/auth'
import { i18nReady } from './lib/i18n'
import './app.css'

// Only attempt to fetch the current user if a session cookie exists.
// Without a session there is no point hitting /api/v1/user — it will
// always return 401 and pollute the console with an error.
const hasSession = document.cookie.includes('XSRF-TOKEN')

if (hasSession) {
  auth
    .user()
    .then((user) => {
      useAuthStore.getState().setUser(user)
    })
    .catch(() => {
      useAuthStore.getState().setLoading(false)
    })
} else {
  useAuthStore.getState().setLoading(false)
}

// Wait for i18n to load translations before rendering to avoid
// showing raw translation keys on initial page load.
i18nReady.then(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
})
