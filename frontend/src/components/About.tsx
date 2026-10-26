import { Button, Card } from './ui';
import { useState, version as reactVersion } from 'react';
import { Check, Copy, Command, Mail, Github, ArrowUpRight } from 'lucide-react';
import { APP_VERSION, PRODUCT } from '../product';
import type { Role } from '../data';

type AboutProps = {
  role: Role;
  systemMetrics?: Record<string, string | number> | null;
};

export default function About({ role, systemMetrics }: AboutProps) {
  const [copyStatus, setCopyStatus] = useState<'idle' | 'copied' | 'failed'>('idle');
  async function copyDetails() {
    try {
      await navigator.clipboard.writeText(`${PRODUCT.name}\nVersion: ${APP_VERSION}\nLicense: ${PRODUCT.license}\nCopyright © ${PRODUCT.copyrightYear} ${PRODUCT.author}`);
      setCopyStatus('copied');
    } catch {
      setCopyStatus('failed');
    }
  }
  return <div className="about-layout"><Card className="panel about-panel" aria-labelledby="about-title">
    <div className="about-heading"><span className="brand-icon" aria-hidden="true"><Command size={24}/></span><div><h2 id="about-title">About {PRODUCT.name}</h2>
    <p>Support tickets, assets, and maintenance contracts in one workspace.</p></div></div>
    <dl className="about-details">
      <dt>App</dt><dd>{PRODUCT.name}</dd>
      <dt>Version</dt><dd>{APP_VERSION}</dd>
      <dt>License</dt><dd>{PRODUCT.license}</dd>
      <dt>Copyright</dt><dd>© {PRODUCT.copyrightYear} {PRODUCT.author}</dd>
      {PRODUCT.company && <><dt>Company</dt><dd>{PRODUCT.company}</dd></>}
      {PRODUCT.email && <><dt>Email</dt><dd><a href={`mailto:${PRODUCT.email}`}>{PRODUCT.email}</a></dd></>}
      {PRODUCT.github && <><dt>GitHub</dt><dd><a href={PRODUCT.github} target="_blank" rel="noopener noreferrer">{PRODUCT.github}</a></dd></>}
    </dl>
    <div className="about-actions">
      <Button type="button" className="btn" onClick={() => void copyDetails()}>{copyStatus === 'copied' ? <Check size={16}/> : <Copy size={16}/>} Copy app details</Button>
      <span role="status">{copyStatus === 'copied' ? 'App details copied.' : copyStatus === 'failed' ? 'Could not copy. Select the details above to copy manually.' : ''}</span>
    </div>
    {role === 'super_admin' && <section className="about-specifications" aria-labelledby="about-specifications-title">
      <h3 id="about-specifications-title">App specifications</h3>
      <dl className="about-details">
        <dt>Deployment</dt><dd>Self-hosted per contractor</dd>
        <dt>Frontend</dt><dd>React {reactVersion} · TypeScript</dd>
        <dt>Build tool</dt><dd>Vite</dd>
        <dt>Backend</dt><dd>{systemMetrics?.laravel_version ? `Laravel ${systemMetrics.laravel_version}` : 'Laravel'}</dd>
        <dt>Runtime</dt><dd>{systemMetrics?.php_version ? `PHP ${systemMetrics.php_version}` : 'PHP'}</dd>
        <dt>Database stack</dt><dd>MariaDB / MySQL</dd>
        <dt>Authentication</dt><dd>Sanctum cookie sessions · MFA and recovery</dd>
      </dl>
    </section>}
  </Card>
  <div className="about-side">
    <Card className="panel about-explore" aria-labelledby="about-explore-title">
      <h3 id="about-explore-title">Explore your workspace</h3>
      <details open><summary>Support tickets</summary><p>Follow requests from reporting to resolution, with updates and work reports kept alongside each ticket.</p></details>
      <details><summary>Assets and maintenance</summary><p>Keep client assets organized by category and track maintenance contracts and scheduled work.</p></details>
      <details><summary>Access and privacy</summary><p>Your user type determines what you can see and manage. Client users see their own tickets and assigned assets; technicians see assigned work.</p></details>
    </Card>
    {(PRODUCT.email || PRODUCT.github) && <Card className="panel about-contact" aria-labelledby="about-contact-title">
      <h3 id="about-contact-title">Get in touch</h3>
      <div className="about-contact-links">
        {PRODUCT.email && <a href={`mailto:${PRODUCT.email}`}><Mail size={20} aria-hidden="true"/><span><b>Email</b><small>{PRODUCT.email}</small></span><ArrowUpRight size={16} aria-hidden="true"/></a>}
        {PRODUCT.github && <a href={PRODUCT.github} target="_blank" rel="noopener noreferrer" aria-label="GitHub profile (opens in a new tab)"><Github size={20} aria-hidden="true"/><span><b>GitHub profile</b><small>{PRODUCT.github}</small></span><ArrowUpRight size={16} aria-hidden="true"/></a>}
      </div>
    </Card>}
    {role === 'super_admin' && (systemMetrics?.queue_connection != null || systemMetrics?.cache_store != null) && <Card className="panel about-services" aria-labelledby="about-services-title">
      <h3 id="about-services-title">Runtime services</h3>
      <dl className="about-details">
        {systemMetrics?.queue_connection != null && <><dt>Queue connection</dt><dd>{systemMetrics.queue_connection}</dd></>}
        {systemMetrics?.cache_store != null && <><dt>Cache store</dt><dd>{systemMetrics.cache_store}</dd></>}
      </dl>
    </Card>}
  </div></div>;
}
