import { useState } from 'react'

const initialForm = {
  endpoint: '',
  apiKey: '',
  description: 'pixel god with glowing prism crown',
  nDirections: 8,
  size: 48,
}

function PixelLabPage() {
  const [form, setForm] = useState(initialForm)
  const [status, setStatus] = useState('idle')
  const [result, setResult] = useState('')

  const onChange = (e) => {
    const { name, value } = e.target
    setForm((prev) => ({ ...prev, [name]: value }))
  }

  const runRequest = async (e) => {
    e.preventDefault()
    setStatus('loading')
    setResult('')

    const payload = {
      tool: 'create_character',
      args: {
        description: form.description,
        n_directions: Number(form.nDirections),
        size: Number(form.size),
      },
    }

    if (!form.endpoint.trim()) {
      setStatus('ok')
      setResult(
        JSON.stringify(
          {
            mode: 'dry-run',
            note: 'Add endpoint to send live request. Payload preview below.',
            payload,
          },
          null,
          2,
        ),
      )
      return
    }

    try {
      const res = await fetch(form.endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(form.apiKey ? { Authorization: `Bearer ${form.apiKey}` } : {}),
        },
        body: JSON.stringify(payload),
      })

      const text = await res.text()
      setStatus(res.ok ? 'ok' : 'error')
      setResult(text)
    } catch (err) {
      setStatus('error')
      setResult(err.message)
    }
  }

  return (
    <section className="panel">
      <h2>PixelLab Request Panel</h2>
      <p className="sub">Use this to prototype a hosted PixelLab create_character request from React.</p>

      <form className="form" onSubmit={runRequest}>
        <label>
          Endpoint URL
          <input name="endpoint" value={form.endpoint} onChange={onChange} placeholder="https://your-api/pixellab" />
        </label>

        <label>
          API Key (optional)
          <input name="apiKey" value={form.apiKey} onChange={onChange} placeholder="token" type="password" />
        </label>

        <label>
          Character Description
          <input name="description" value={form.description} onChange={onChange} />
        </label>

        <div className="grid2">
          <label>
            Directions
            <select name="nDirections" value={form.nDirections} onChange={onChange}>
              <option value={4}>4</option>
              <option value={8}>8</option>
            </select>
          </label>

          <label>
            Size
            <input name="size" type="number" min={16} max={128} value={form.size} onChange={onChange} />
          </label>
        </div>

        <button type="submit" disabled={status === 'loading'}>
          {status === 'loading' ? 'Sending…' : 'Send Request'}
        </button>
      </form>

      <pre className={`output ${status}`}>{result || 'No result yet.'}</pre>
    </section>
  )
}

export default PixelLabPage
