const prompts = [
  'Create a pixel-art wizard NPC, 8 directions, 48x48, high detail.',
  'Write 5 ad hooks for indie devs launching a pixel game.',
  'Build a React inventory UI with drag-drop slots and keyboard nav.',
]

function HomePage() {
  return (
    <section className="panel">
      <p className="sub">
        Fast starter for your projects. Use the PixelLab panel to test prompt → payload → response flow.
      </p>

      <h2>Prompt Ideas</h2>
      <ul>
        {prompts.map((p) => (
          <li key={p}>{p}</li>
        ))}
      </ul>

      <div className="actions">
        <a href="https://react.dev/learn" target="_blank" rel="noreferrer">
          Learn React
        </a>
      </div>
    </section>
  )
}

export default HomePage
