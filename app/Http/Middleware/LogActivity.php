<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ActivityLog;

class LogActivity
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log for authenticated users and successful requests
        if (auth()->check() && $response->getStatusCode() < 400) {
            $this->logActivity($request, $response);
        }

        return $response;
    }

    /**
     * Log the activity
     */
    private function logActivity(Request $request, Response $response)
    {
        $method = $request->method();
        $route = $request->route();
        $routeName = $route ? $route->getName() : null;
        $uri = $request->getRequestUri();

        // Skip logging for certain routes
        $skipRoutes = [
            'admin.activity-logs',
            'admin.export-activity-logs',
            'dashboard',
            'admin.dashboard',
            'pharmacist.dashboard',
            'notifications.recent',
            'notifications.count'
        ];

        if (in_array($routeName, $skipRoutes) || str_contains($uri, '/api/')) {
            return;
        }

        // Determine action based on HTTP method and route
        $action = $this->determineAction($method, $routeName, $uri);
        $description = $this->generateDescription($action, $routeName, $request);

        // Create activity log safely
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $action,
                'description' => $description,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Silently fail to prevent breaking the application
            \Log::error('Failed to log activity: ' . $e->getMessage());
        }
    }

    /**
     * Determine the action based on method and route
     */
    private function determineAction(string $method, ?string $routeName, string $uri): string
    {
        if ($method === 'GET') {
            if (str_contains($uri, '/export') || str_contains($uri, '/download')) {
                return 'export';
            }
            return 'view';
        }

        if ($method === 'POST') {
            return 'create';
        }

        if (in_array($method, ['PUT', 'PATCH'])) {
            return 'update';
        }

        if ($method === 'DELETE') {
            return 'delete';
        }

        return 'action';
    }

    /**
     * Generate description for the activity
     */
    private function generateDescription(string $action, ?string $routeName, Request $request): string
    {
        if (!$routeName) {
            return "Action {$action} sur une ressource";
        }

        $descriptions = [
            // Sales
            'sales.index' => 'Consultation de la liste des ventes',
            'sales.show' => 'Consultation d\'une vente',
            'sales.create' => 'Affichage du formulaire de nouvelle vente',
            'sales.store' => 'Création d\'une nouvelle vente',
            'sales.edit' => 'Affichage du formulaire de modification de vente',
            'sales.update' => 'Modification d\'une vente',
            'sales.destroy' => 'Suppression d\'une vente',
            
            // Inventory
            'inventory.index' => 'Consultation de l\'inventaire',
            'inventory.show' => 'Consultation d\'un produit',
            'inventory.create' => 'Affichage du formulaire de nouveau produit',
            'inventory.store' => 'Ajout d\'un nouveau produit',
            'inventory.edit' => 'Affichage du formulaire de modification de produit',
            'inventory.update' => 'Modification d\'un produit',
            'inventory.destroy' => 'Suppression d\'un produit',
            
            // Clients
            'clients.index' => 'Consultation de la liste des clients',
            'clients.show' => 'Consultation d\'un client',
            'clients.create' => 'Affichage du formulaire de nouveau client',
            'clients.store' => 'Création d\'un nouveau client',
            'clients.edit' => 'Affichage du formulaire de modification de client',
            'clients.update' => 'Modification d\'un client',
            'clients.destroy' => 'Suppression d\'un client',
        ];

        return $descriptions[$routeName] ?? "Action {$action} sur " . str_replace('.', ' ', $routeName);
    }
}