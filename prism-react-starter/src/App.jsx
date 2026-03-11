import { NavLink, Navigate, Route, Routes } from 'react-router-dom'
import HomePage from './pages/HomePage'
import PixelLabPage from './pages/PixelLabPage'
import './App.css'

function App() {
  return (
    <main className="shell">
      <header className="topbar panel">
        <div>
          <p className="eyebrow">Prismtek Starter</p>
          <h1>React + Pixel Vibes</h1>
        </div>

        <nav className="nav">
          <NavLink to="/" end>
            Home
          </NavLink>
          <NavLink to="/pixellab">PixelLab Panel</NavLink>
        </nav>
      </header>

      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/pixellab" element={<PixelLabPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </main>
  )
}

export default App
