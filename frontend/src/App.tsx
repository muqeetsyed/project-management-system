import { useEffect, useState } from 'react'
import './App.css'

type HealthResponse = {
  status: string
  database: string
}

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

function App() {
  const [health, setHealth] = useState<HealthResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetch(`${API_URL}/api/health`)
      .then((res) => res.json())
      .then(setHealth)
      .catch(() => setError('Could not reach the backend API'))
  }, [])

  return (
    <main style={{ fontFamily: 'sans-serif', padding: '2rem' }}>
      <h1>Project Management System</h1>
      <p>Symfony + React + PostgreSQL base setup</p>

      {error && <p style={{ color: 'crimson' }}>{error}</p>}
      {!error && !health && <p>Checking backend status…</p>}
      {health && (
        <ul>
          <li>API status: {health.status}</li>
          <li>Database: {health.database}</li>
        </ul>
      )}
    </main>
  )
}

export default App
