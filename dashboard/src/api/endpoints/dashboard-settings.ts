import { api } from '../client'

export interface UserSettings {
  name: string
  email: string
}

export const dashboardSettings = {
  async get(): Promise<UserSettings> {
    const res = await api.get('dashboard/settings').json<{ data: UserSettings }>()
    return res.data
  },

  async update(data: Partial<UserSettings>): Promise<UserSettings> {
    const res = await api
      .patch('dashboard/settings', { json: data })
      .json<{ data: UserSettings }>()
    return res.data
  },

  async changePassword(data: {
    current_password: string
    new_password: string
    new_password_confirmation: string
  }): Promise<void> {
    await api.patch('dashboard/settings', { json: data })
  },
} as const
