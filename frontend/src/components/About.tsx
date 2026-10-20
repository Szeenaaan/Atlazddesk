import { APP_VERSION, PRODUCT } from '../product';

export default function About() {
  return <section className="panel about-panel" aria-labelledby="about-title">
    <h2 id="about-title">About {PRODUCT.name}</h2>
    <p>Support tickets, assets, and maintenance contracts in one workspace.</p>
    <dl className="about-details">
      <dt>App</dt><dd>{PRODUCT.name}</dd>
      <dt>Version</dt><dd>{APP_VERSION}</dd>
      <dt>Created by</dt><dd>{PRODUCT.author}</dd>
      {PRODUCT.company && <><dt>Company</dt><dd>{PRODUCT.company}</dd></>}
      {PRODUCT.email && <><dt>Email</dt><dd><a href={`mailto:${PRODUCT.email}`}>{PRODUCT.email}</a></dd></>}
      {PRODUCT.github && <><dt>GitHub</dt><dd><a href={PRODUCT.github} target="_blank" rel="noopener noreferrer">{PRODUCT.github}</a></dd></>}
    </dl>
  </section>;
}
