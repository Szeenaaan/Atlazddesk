import './styles/index.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import MaintenanceGate from './components/MaintenanceGate';
import { initializeTheme } from './components/ThemeToggle';

initializeTheme();

const root = document.getElementById('root');
if (!root) throw new Error('React root element is missing');
createRoot(root).render(<MaintenanceGate><App /></MaintenanceGate>);
